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
 * One account write for tests/account-race.php. Prints "ok", or "threw" and the error.
 *
 * Usage:  php tests/lib/account-race-child.php <database> <profile|disable|delete|confirm> <user id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $action, $userId] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();
foreach (['enabled_users' => '1', 'language' => 'en_US'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
$_COOKIE['oc_userLocale'] = 'en_US';

use mindstellar\auth\Actor;
use mindstellar\user\AccountService;

$service = new AccountService();
$staff   = Actor::admin(1);
try {
    switch ($action) {
        case 'profile':
            $service->update((int) $userId, ['s_name' => 'Renamed', 's_username' => 'renamed', 's_email' => 'sue@example.test'], $staff);
            break;
        case 'disable':
            $service->disable((int) $userId, $staff);
            break;
        case 'delete':
            $service->delete((int) $userId, $staff);
            break;
        case 'confirm':
            $service->confirm((int) $userId, 'code');
            break;
    }
    echo "ok\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
