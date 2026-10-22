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
 * On a demo site the API refuses what the admin screens refuse, because every visitor knows
 * the admin login. Usage: php tests/models/api-demo-lock.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\apikey\ApiAccess;
use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\exception\ForbiddenException;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

$admin = api_admin_boot('osc_models_api_demo_lock');
$p     = DB_TABLE_PREFIX;
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';

$bossId = api_admin_seed_admin($admin, 'boss');
$boss   = api_admin_key($bossId);
$owner  = KeyOwner::admin($bossId);
$target = (int) (new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock()))->create(CredentialKind::KEY, 'target', ['admin:listings'], $owner)->id();
$call   = api_admin_caller();
$count  = static fn (string $table): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}{$table}")->fetch_row()[0];
$live   = static fn (int $id): bool => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_api_credential WHERE pk_i_id = $id AND dt_revoked IS NULL")->fetch_row()[0] === 1;

/**
 * The reason a core call refused with, or '' when it went through.
 */
function demo_refusal(callable $write): string
{
    try {
        $write();
    } catch (ForbiddenException $e) {
        return $e->reason();
    }

    return '';
}

// Defined after the seeding, because it cannot be undefined.
define('DEMO', true);

harness_section('keys');
$keys = $count('t_api_credential');
pin('making a key is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/keys', ['name' => 'Kept', 'scopes' => ['admin:listings']], $boss)));
pin('rotating a key is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/keys/' . $target . '/rotate', null, $boss)));
pin('revoking a key is 403 demo_site', '403 demo_site', api_admin_code($call('DELETE', 'admin/keys/' . $target, null, $boss)));
pin('no key is made and the old one still works', [$keys, true], [$count('t_api_credential'), $live($target)]);
pin('the service the admin screen and the CLI use refuses too', [ForbiddenException::DEMO, ForbiddenException::DEMO, ForbiddenException::DEMO], [
    demo_refusal(static fn () => ApiAccess::site()->keyService()->create($owner, 'Web', CredentialKind::KEY, ['admin:listings'])),
    demo_refusal(static fn () => ApiAccess::site()->keyService()->rotate($target, $bossId)),
    demo_refusal(static fn () => ApiAccess::site()->keyService()->revoke($target)),
]);
pin('still no key is made', $keys, $count('t_api_credential'));

exit(harness_result());
