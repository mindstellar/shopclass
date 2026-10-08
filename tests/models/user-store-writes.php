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
 * Pins the alert link and admin writes in UserAlerts, BanRuleStore::delete,
 * UserStore::touchAccess and LatestSearchStore::record.
 *
 * Usage:  php tests/models/user-store-writes.php          (standalone, own scratch database)
 *         php tests/run-models.php user-store-writes      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\search\AlertEnvelope;
use mindstellar\search\LatestSearchStore;
use mindstellar\search\UserAlerts;
use mindstellar\security\BanRuleStore;
use mindstellar\user\UserStore;

$admin  = scratchdb_session('osc_models_user_store_writes');
$prefix = DB_TABLE_PREFIX;

$seedAlert = static function (string $email, ?int $userId, string $search, string $secret, int $active = 0) use ($admin, $prefix): int {
    return seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_alerts (s_email, fk_i_user_id, s_search, s_secret, b_active, e_type, dt_date)"
        . " VALUES (?, ?, ?, ?, ?, 'DAILY', NOW())",
        'sissi',
        array($email, $userId, $search, $secret, $active)
    );
};
$alertRow = static function (int $id) use ($admin, $prefix): ?array {
    return $admin->query("SELECT * FROM {$prefix}t_alerts WHERE pk_i_id = $id")->fetch_assoc();
};
$userRow = static function (int $id) use ($admin, $prefix): ?array {
    return $admin->query("SELECT dt_access_date, s_access_ip FROM {$prefix}t_user WHERE pk_i_id = $id")->fetch_assoc();
};

$service = new UserAlerts();
$search  = '{"v":2,"q":"bike"}';

harness_section('UserAlerts::activateByLink');

$owner = seed_user($admin, 'owner', 'owner@example.test');
$a     = $seedAlert('owner@example.test', null, $search, 'sekret');

pin('a wrong secret fails', UserAlerts::FAILED, $service->activateByLink($a, 'owner@example.test', 'nope'));
pin('a wrong e-mail fails', UserAlerts::FAILED, $service->activateByLink($a, 'other@example.test', 'sekret'));
pin('the alert is still off', '0', (string) $alertRow($a)['b_active']);
pin('a missing alert fails', UserAlerts::FAILED, $service->activateByLink(999999, 'owner@example.test', 'sekret'));
pin('the right link activates', UserAlerts::ACTIVATED, $service->activateByLink($a, 'owner@example.test', 'sekret'));
pin('the alert is on', '1', (string) $alertRow($a)['b_active']);
pin('the alert went to the account with that e-mail', (string) $owner, (string) $alertRow($a)['fk_i_user_id']);
pin('a second click changes nothing and fails, as before', UserAlerts::FAILED, $service->activateByLink($a, 'owner@example.test', 'sekret'));

$held = $seedAlert('owner@example.test', $owner, AlertEnvelope::held('legacy'), 'sekret');
pin('a held alert answers HELD', UserAlerts::HELD, $service->activateByLink($held, 'owner@example.test', 'sekret'));
pin('a held alert stays off', '0', (string) $alertRow($held)['b_active']);

$guest = $seedAlert('guest@example.test', null, $search, 'g1');
pin('an e-mail with no account still activates', UserAlerts::ACTIVATED, $service->activateByLink($guest, 'guest@example.test', 'g1'));
check('with no account the alert has no owner', $alertRow($guest)['fk_i_user_id'] === null);

harness_section('UserAlerts::unsubscribeByLink');

check('a wrong secret does not unsubscribe', !$service->unsubscribeByLink($a, 'owner@example.test', 'nope'));
check('nothing was written', $alertRow($a)['dt_unsub_date'] === null);
check('the right link unsubscribes', $service->unsubscribeByLink($a, 'owner@example.test', 'sekret'));
check('dt_unsub_date was set', $alertRow($a)['dt_unsub_date'] !== null);

harness_section('UserAlerts admin: setActive and delete');

$b = $seedAlert('owner@example.test', $owner, $search, 's2', 1);
check('switching off works', $service->setActive($b, false));
pin('the alert is off', '0', (string) $alertRow($b)['b_active']);
check('switching off again changes nothing', !$service->setActive($b, false));
check('switching on works', $service->setActive($b, true));
pin('the alert is on', '1', (string) $alertRow($b)['b_active']);
check('a held alert is not switched on', !$service->setActive($held, true));
check('delete works', $service->delete($b));
check('the row is gone', $alertRow($b) === null);
check('deleting it again fails', !$service->delete($b));

harness_section('BanRuleStore::delete');

$rule  = seed_exec($admin, "INSERT INTO {$prefix}t_ban_rule (s_name, s_ip, s_email) VALUES (?, ?, ?)", 'sss', array('r', '1.2.3.4', ''));
$ids   = static fn (): array => array_map(static fn ($r) => (int) $r['pk_i_id'], BanRuleStore::cached());
check('the rule is listed (and cached)', in_array($rule, $ids(), true));
pin('delete removes one rule', 1, BanRuleStore::delete($rule));
check('the cached list no longer has it', !in_array($rule, $ids(), true));
pin('deleting a missing rule removes none', 0, BanRuleStore::delete($rule));

harness_section('UserStore::touchAccess');

$visitor = seed_user($admin, 'visitor', 'visitor@example.test');
$admin->query("UPDATE {$prefix}t_user SET dt_access_date = '2020-01-01 00:00:00', s_access_ip = '' WHERE pk_i_id = $visitor");
check('an old stamp is replaced', UserStore::touchAccess($visitor, '2026-10-08 12:00:00', '10.0.0.1', 3600));
pin('the new stamp was written', array('dt_access_date' => '2026-10-08 12:00:00', 's_access_ip' => '10.0.0.1'), $userRow($visitor));
$admin->query("UPDATE {$prefix}t_user SET dt_access_date = NOW() WHERE pk_i_id = $visitor");
check('a recent stamp is left alone', !UserStore::touchAccess($visitor, '2030-01-01 00:00:00', '10.0.0.2', 3600));
pin('the ip was not changed', '10.0.0.1', $userRow($visitor)['s_access_ip']);

harness_section('LatestSearchStore::record');

LatestSearchStore::record('red bike', '2026-10-08 12:00:00');
pin(
    'the search was written',
    array('d_date' => '2026-10-08 12:00:00', 's_search' => 'red bike'),
    $admin->query("SELECT d_date, s_search FROM {$prefix}t_latest_searches WHERE s_search = 'red bike'")->fetch_assoc()
);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
