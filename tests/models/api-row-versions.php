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
 * RowVersions on real tables: a version is stable, the same for a resource's public and
 * admin paths, changes when a child row does, ignores a user's last access, is null for a
 * missing row; a locked read inside atomically() holds the row against another connection
 * until commit, and a throw rolls the transaction back.
 *
 * Usage:  php tests/models/api-row-versions.php        (standalone, own scratch database)
 *         php tests/run-models.php api-row-versions    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\api\auth\Credential;
use mindstellar\api\auth\CredentialKind;
use mindstellar\api\http\RowVersions;
use mindstellar\database\Connection;

if (!defined('OSC_CSRF_SECRET')) {
    define('OSC_CSRF_SECRET', 'row-versions-test-secret');
}

$admin    = scratchdb_session('osc_models_api_row_versions');
$p        = DB_TABLE_PREFIX;
seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$user     = seed_user($admin);
$item     = seed_item($admin, seed_category($admin), $user);
$versions = new RowVersions();
$nobody   = Credential::anonymous();
$owner    = new Credential(CredentialKind::KEY, ['account:write'], $user);
$listing  = static fn (): ?string => $versions->version('listings/{id}', ['id' => (string) $item], $nobody);

harness_section('version');
$v1 = $listing();
pin('a listing has a 24-character version, the same when read again', [24, $v1], [strlen((string) $v1), $listing()]);
pin('its admin path has the same one', $v1, $versions->version('admin/listings/{id}', ['id' => (string) $item], $nobody));
$admin->query("UPDATE {$p}t_item_description SET s_title = 'Edited' WHERE fk_i_item_id = $item");
$v2 = $listing();
pin('editing its description changes it', true, $v2 !== $v1);
pin('a missing listing has none', null, $versions->version('listings/{id}', ['id' => '999999'], $nobody));
pin('a path with no stored version is not supported', [false, true], [$versions->supports('admin/settings'), $versions->supports('listings/{id}')]);

$account = $versions->version('account', [], $owner);
$admin->query("UPDATE {$p}t_user SET dt_access_date = NOW() + INTERVAL 1 DAY, s_access_ip = '192.0.2.9' WHERE pk_i_id = $user");
pin('the account\'s is the user\'s, and a new access does not change it', [$account, $account], [$versions->version('admin/users/{id}', ['id' => (string) $user], $nobody), $versions->version('account', [], $owner)]);
$admin->query("UPDATE {$p}t_user SET s_name = 'Renamed' WHERE pk_i_id = $user");
pin('a new name does', true, $versions->version('account', [], $owner) !== $account);
pin('an anonymous credential has no account', null, $versions->version('account', [], $nobody));

harness_section('lock');
$blocked = static function () use ($admin, $p, $item): bool {
    $admin->query('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        return $admin->query("UPDATE {$p}t_item SET b_premium = 1 - b_premium WHERE pk_i_id = $item") === false && $admin->errno === 1205;
    } catch (mysqli_sql_exception $e) {
        return $e->getCode() === 1205;
    }
};
$inside = $versions->atomically(static function () use ($versions, $item, $nobody, $blocked): array {
    return [$versions->version('listings/{id}', ['id' => (string) $item], $nobody, true), $blocked()];
});
pin('a locked read holds the row against another writer until commit', [$v2, true], $inside);
pin('after which the row can be written again', false, $blocked());

try {
    $versions->atomically(static function () use ($item, $p): void {
        Connection::getInstance()->execute("UPDATE {$p}t_item_description SET s_title = 'Lost' WHERE fk_i_item_id = ?", [$item]);

        throw new RuntimeException('handler failed');
    });
} catch (RuntimeException $e) {
}
pin('a throw rolls the transaction back', 'Edited', $admin->query("SELECT s_title FROM {$p}t_item_description WHERE fk_i_item_id = $item")->fetch_row()[0]);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
