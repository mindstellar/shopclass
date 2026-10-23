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
 * Push subscriptions and privacy: the export lists a user's browsers by push-service host only
 * (never the full address), skips other users' rows, and deleting the account removes the rows.
 *
 * Usage:  php tests/models/push-privacy.php      (standalone, own scratch database)
 *         php tests/run-models.php push-privacy  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\model\KeyValue;
use mindstellar\privacy\PersonalData;
use mindstellar\push\PushSubscriptions;
use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;

$admin = api_admin_boot('osc_models_push_privacy');
$p     = DB_TABLE_PREFIX;

final class PpClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}

$subs = new PushSubscriptions(new KeyValue(), new PpClock(), new AddressGuard(static fn (string $host): array => ['142.250.1.1']));
$ep   = static fn (string $tail): string => 'https://fcm.googleapis.com/fcm/send/' . $tail;
$rows = static fn () => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'push'")->fetch_row()[0];

$subs->add(12, $ep('secret-token-a'), 'Chrome/130.0');
$subs->add(12, 'https://updates.push.services.mozilla.com/wpush/v2/secret-token-b', 'Firefox/131.0');
$subs->add(123, $ep('other-user'), 'Chrome/130.0');
$subs->add(13, $ep('another-user'), 'Chrome/130.0');

harness_section('export');
$out  = PushSubscriptions::export(['data' => [], 'not_included' => []], 12);
$part = $out['data']['push_subscriptions'] ?? [];
pin('retention says deleted with the account', PersonalData::ERASE_DELETED, $part['retention'] ?? null);
pin('the reason', 'Browsers they asked to notify about new messages.', $part['why'] ?? null);
pin('two browsers listed', 2, count($part['rows'] ?? []));
$hosts = array_column($part['rows'] ?? [], 'endpoint');
sort($hosts);
pin('only the push service host', ['fcm.googleapis.com', 'updates.push.services.mozilla.com'], $hosts);
pin('rows carry browser id, ua and seen', ['browser', 'endpoint', 'seen', 'ua'], (static function () use ($part) {
    $k = array_keys($part['rows'][0] ?? []);
    sort($k);

    return $k;
})());
$json = json_encode($out);
pin('no full endpoint in the export', false, str_contains($json, 'secret-token') || str_contains($json, '/fcm/send') || str_contains($json, 'other-user'));
pin('a user with none gets an empty list', [], PushSubscriptions::export(['data' => []], 999)['data']['push_subscriptions']['rows']);
pin('a non-array passes through', 'x', PushSubscriptions::export('x', 12));

harness_section('delete_user');
$wiring = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php');
$pushClass = preg_quote('\\mindstellar\\push\\PushSubscriptions::class', '/');
pin('the export filter is registered', 1, preg_match("/osc_add_filter\\('personal_data_export', array\\($pushClass, 'export'\\)\\)/", $wiring));
pin('the delete_user hook is registered', 1, preg_match("/osc_add_hook\\('delete_user', array\\($pushClass, 'erase'\\)\\)/", $wiring));
pin('four rows to start', 4, $rows());
PushSubscriptions::erase(12);
pin('their rows are gone', [], $subs->forUser(12));
pin('and no one else\'s', 2, $rows());
pin('user 123 kept', 1, count($subs->forUser(123)));
pin('user 13 kept', 1, count($subs->forUser(13)));

exit(harness_result());

/* file end: ./tests/models/push-privacy.php */
