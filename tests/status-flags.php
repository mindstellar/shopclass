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
 * StatusFlags: the actions for several status flags at once, an unblock first, a block last,
 * and none for a flag already as asked.
 *
 * DB-free.  Usage:  php tests/status-flags.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\moderation\CommentModeration;
use mindstellar\moderation\ListingModeration;
use mindstellar\moderation\StatusFlags;
use mindstellar\user\AccountService;

$blocked = ['b_enabled' => '0', 'b_active' => '0', 'b_spam' => '0', 'b_premium' => '0'];
$live    = ['b_enabled' => '1', 'b_active' => '1', 'b_spam' => '0', 'b_premium' => '0'];

pin('approving a blocked listing unblocks it first', ['enable', 'activate'], StatusFlags::plan(['active' => true, 'blocked' => false], $blocked, ListingModeration::FLAG_ACTIONS));
pin('a block runs last, whatever order the flags came in', ['spam', 'premium', 'disable'], StatusFlags::plan(['blocked' => true, 'premium' => true, 'spam' => true], $live, ListingModeration::FLAG_ACTIONS));
pin('a flag already as asked runs nothing', [], StatusFlags::plan(['active' => true, 'blocked' => false, 'spam' => false], $live, ListingModeration::FLAG_ACTIONS));
pin('comments follow the same rule', ['enable', 'activate'], StatusFlags::plan(['blocked' => false, 'active' => true], $blocked, CommentModeration::FLAG_ACTIONS));
pin('and users', ['deactivate', 'disable'], StatusFlags::plan(['blocked' => true, 'active' => false], $live, AccountService::FLAG_ACTIONS));
$refused = false;
try {
    StatusFlags::plan(['spam' => true], $live, CommentModeration::FLAG_ACTIONS);
} catch (LogicException $e) {
    $refused = true;
}
check('a flag the object does not have is refused', $refused);

exit(harness_result());
