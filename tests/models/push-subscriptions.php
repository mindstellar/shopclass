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
 * Push subscriptions on the real key-value table: one row per user and browser, a user's
 * list never reaches the next user's rows, renewal moves the expiry, removal by user, key or
 * endpoint. The VAPID pair is made once even when two instances race for it, and
 * osc_push_enabled() needs an https:// (or localhost) site address.
 *
 * Usage:  php tests/models/push-subscriptions.php      (standalone, own scratch database)
 *         php tests/run-models.php push-subscriptions  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\container\Container;
use mindstellar\database\Db;
use mindstellar\live\LiveStatus;
use mindstellar\model\KeyValue;
use mindstellar\push\PushSubscriptions;
use mindstellar\push\Vapid;
use mindstellar\push\WebPushSender;
use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;

$GLOBALS['psBase'] = 'http://localhost/';

/** The site address, changed per section. */
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return $GLOBALS['psBase'] . ($with_index ? 'index.php' : '');
    }
}

$admin = api_admin_boot('osc_models_push_subscriptions');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$p = DB_TABLE_PREFIX;

final class PsClock implements Clock
{
    public function __construct(public int $t)
    {
    }

    public function now(): int
    {
        return $this->t;
    }
}

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$count = static function (string $sql) use ($admin): int {
    return (int) $admin->query($sql)->fetch_row()[0];
};
$forgetKeys = static function () use ($p): void {
    Db::execute('DELETE FROM ' . $p . "t_preference WHERE s_section = 'osclass' AND s_name IN (?, ?)", [Vapid::PUBLIC_PREF, Vapid::PRIVATE_PREF]);
    Preference::getInstance()->set(Vapid::PUBLIC_PREF, '');
    Preference::getInstance()->set(Vapid::PRIVATE_PREF, '');
    scratchdb_forget_cache();
};

$clock = new PsClock(time());
$kv    = new KeyValue();
$guard = new AddressGuard(static fn (string $host): array => ['142.250.1.1']);
$subs  = new PushSubscriptions($kv, $clock, $guard);
$ep    = static fn (string $tail): string => 'https://fcm.googleapis.com/fcm/send/' . $tail;
$rows  = static fn () => $count("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'push'");

harness_section('add');
$id1 = $subs->add(12, $ep('a'), 'Mozilla/5.0 Chrome/130.0 Safari/537.36');
$id2 = $subs->add(12, $ep('a'), 'Mozilla/5.0 Chrome/130.0 Safari/537.36');
pin('the same endpoint twice: the same browser id', $id1, $id2);
pin('and one row', 1, $rows());
pin('the browser id is the endpoint hash', PushSubscriptions::browserId($ep('a')), $id1);
$row = $kv->get('push', '12:' . $id1);
pin('value is endpoint, UA family, seen', ['endpoint' => $ep('a'), 'ua' => 'Chrome', 'seen' => $clock->t], json_decode((string) $row['value'], true));
pin('expires 90 days from now', $clock->t + 90 * 86400, $row['expires']);
foreach ([
    'http://' => 'http://fcm.googleapis.com/fcm/send/a',
    'loopback' => 'https://127.0.0.1/x',
    'private' => 'https://10.0.0.5/x',
    'over 1024 bytes' => $ep(str_repeat('a', 1024)),
    'empty' => '',
] as $label => $bad) {
    $threw = false;
    try {
        $subs->add(12, $bad, 'Chrome');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    pin('refused: ' . $label, true, $threw);
}
pin('nothing stored for a refused address', 1, $rows());

harness_section('forUser keeps to one user');
$subs->add(12, $ep('b'), 'Firefox/131.0');
$subs->add(123, $ep('c'), 'Chrome/130.0');
$subs->add(13, $ep('d'), 'Chrome/130.0');
$subs->add(2, $ep('e'), 'Chrome/130.0');
$mine = $subs->forUser(12);
pin('user 12 has two browsers', 2, count($mine));
pin('every key is theirs', true, array_filter(array_keys($mine), static fn ($k) => !str_starts_with($k, '12:')) === []);
pin('none of user 123 or 13', [$ep('a'), $ep('b')], (static function () use ($mine) {
    $e = array_column($mine, 'endpoint');
    sort($e);

    return $e;
})());
pin('user 123 sees its own only', [$ep('c')], array_column($subs->forUser(123), 'endpoint'));
pin('user 13 sees its own only', [$ep('d')], array_column($subs->forUser(13), 'endpoint'));
pin('no user: nothing', [], $subs->forUser(0));
for ($i = 0; $i < 25; $i++) {
    $subs->add(7, $ep('many' . $i), 'Chrome/130.0');
}
pin('at most 20 per user', 20, count($subs->forUser(7)));

harness_section('renew');
$clock->t += 10 * 86400;
pin('renew an active browser', true, $subs->renew(12, $ep('a')));
pin('expiry moved to 90 days from the renewal', $clock->t + 90 * 86400, $kv->get('push', '12:' . $id1, $clock->t)['expires']);
pin('a second renewal in the same second still says active', true, $subs->renew(12, $ep('a')));
pin('another user with that endpoint: not theirs', false, $subs->renew(123, $ep('a')));
pin('an unknown endpoint: false', false, $subs->renew(12, $ep('zzz')));

harness_section('remove');
pin('remove by user and endpoint', 1, $subs->remove(12, $ep('b')));
pin('another user cannot remove it', 0, $subs->remove(123, $ep('a')));
pin('removeEndpoint by full key', 1, $subs->removeEndpoint('13:' . PushSubscriptions::browserId($ep('d'))));
pin('removeEndpoint refuses a malformed key', 0, $subs->removeEndpoint('vapid-init'));
pin('user 13 has none left', [], $subs->forUser(13));
$subs->add(12, $ep('f'), 'Chrome/130.0');
pin('removeUser(12) removes both of theirs', 2, $subs->removeUser(12));
pin('and leaves user 123 alone', 1, count($subs->forUser(123)));
pin('and user 2', 1, count($subs->forUser(2)));

harness_section('count');
$kv->claim('push', Vapid::LOCK_KEY, $clock->t + 30, 'locked', null, $clock->t);
pin('live browser rows only: the lock does not count', 27, $subs->count());
$kv->delete('push', Vapid::LOCK_KEY);
$kv->set('push', '99:' . PushSubscriptions::browserId($ep('old')), '{}', $clock->t - 5, null, $clock->t - 100);
pin('an expired row does not count', 27, $subs->count());

harness_section('VAPID keys: one pair however many instances');
$forgetKeys();
$a = new Vapid($kv, $clock);
$b = new Vapid($kv, $clock);
$keyA = $a->publicKey();
pin('a 65-byte point', 87, strlen($keyA));
pin('the second instance gets the same key', $keyA, $b->publicKey());
pin('one public key row', 1, $count("SELECT COUNT(*) FROM {$p}t_preference WHERE s_name = '" . Vapid::PUBLIC_PREF . "'"));
pin('the lock is let go', null, $kv->get('push', Vapid::LOCK_KEY, $clock->t));

// Another request finished the pair after this one loaded its preferences.
Preference::getInstance()->set(Vapid::PUBLIC_PREF, '');
Preference::getInstance()->set(Vapid::PRIVATE_PREF, '');
pin('winning the lock re-reads before making a pair', $keyA, (new Vapid($kv, $clock))->publicKey());

// Another request holds the lock and writes the pair while this one waits.
$forgetKeys();
$kv->claim('push', Vapid::LOCK_KEY, $clock->t + 30, 'locked', null, $clock->t);
Db::execute('INSERT INTO ' . $p . "t_preference (s_section, s_name, s_value, e_type) VALUES ('osclass', ?, 'pub-from-other', 'STRING'), ('osclass', ?, 'pem-from-other', 'STRING')", [Vapid::PUBLIC_PREF, Vapid::PRIVATE_PREF]);
pin('losing the lock takes the winner\'s pair', 'pub-from-other', (new Vapid($kv, $clock))->publicKey());
pin('and makes none of its own', 'pub-from-other', $count("SELECT COUNT(*) FROM {$p}t_preference WHERE s_value = 'pub-from-other'") === 1 ? 'pub-from-other' : 'changed');

$forgetKeys();
$threw = false;
$start = microtime(true);
try {
    (new Vapid($kv, $clock))->publicKey();
} catch (\RuntimeException $e) {
    $threw = true;
}
pin('the lock held and no pair after the wait: throws', true, $threw);
pin('and waits only a few seconds', true, microtime(true) - $start < 5);
$kv->delete('push', Vapid::LOCK_KEY);

harness_section('push status row');
harness_reset_container();
$kv->delete('live', 'publish');
$kv->delete('live', 'push');
Container::root()->get(WebPushSender::STATUS)->failed('HTTP 403');
pin('push writes its own row', 'HTTP 403', Container::root()->get(WebPushSender::STATUS)->last()['error'] ?? null);
pin('under group live, key push', true, $kv->get('live', 'push') !== null);
pin('the live publish row is untouched', null, Container::root()->get(LiveStatus::class)->last());
$kv->delete('live', 'push');

harness_section('osc_push_enabled');
$forgetKeys();
harness_reset_container();
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('enabled_push', '0');
pin('switch off: off', false, osc_push_enabled());
pin('switch off: no key', '', osc_push_public_key());
$set('enabled_push', '1');
$GLOBALS['psBase'] = 'http://example.com/';
pin('switch on, http:// site: off', false, osc_push_enabled());
pin('and no key', '', osc_push_public_key());
$GLOBALS['psBase'] = 'http://localhost/';
pin('switch on, http://localhost: on', true, osc_push_enabled());
$GLOBALS['psBase'] = 'https://example.com/sub/';
pin('switch on, https://: on', true, osc_push_enabled());
$key = osc_push_public_key();
pin('the key comes from the container\'s Vapid', Container::root()->get(Vapid::class)->publicKey(), $key);
pin('a base64url point', 87, strlen($key));
$set('enabled_messaging', '0');
pin('messaging off: off', false, osc_push_enabled());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
