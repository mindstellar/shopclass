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
 * One comment post for tests/comment-count-race.php. Prints "ok", or "threw" and the error.
 *
 * Usage:  php tests/lib/comment-count-child.php <database> <listing id> <user id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $listing, $user] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();
foreach (['enabled_users' => '1', 'enabled_comments' => '1', 'moderate_comments' => '-1', 'reg_user_post_comments' => '0', 'language' => 'en_US'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
$_COOKIE['oc_userLocale'] = 'en_US';

try {
    (new \mindstellar\comment\CommentService())->post((int) $listing, ['body' => 'Same time'], \mindstellar\auth\Actor::user((int) $user));
    echo "ok\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
