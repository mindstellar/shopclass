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
 * Comments had no limit and were taken on listings the public cannot see. Now a user, or an
 * address for a guest, may post 20 an hour, and a listing that is not live takes comments
 * only from its owner.
 *
 * Usage:  php tests/models/comment-guard.php          (standalone, own scratch database)
 *         php tests/run-models.php comment-guard      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_comment_guard');

require_once __DIR__ . '/../lib/action-standins.php';

$table = DB_TABLE_PREFIX . 't_rate_counter';
$admin->query("TRUNCATE TABLE $table");

$burst = static function ($userId, int $times): array {
    $got = array();
    for ($i = 0; $i < $times; $i++) {
        $got[] = ItemActions::commentLimitReached($userId);
    }

    return $got;
};

harness_section('the hourly comment limit');
pin('the limit is 20 an hour', 20, ItemActions::COMMENTS_PER_HOUR);

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
Params::init();
$guest = $burst(null, 21);
pin('a guest posts 20 comments', array_fill(0, 20, false), array_slice($guest, 0, 20));
pin('the 21st from the same address is refused', true, $guest[20]);

$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
Params::init();
pin('another address has its own count', false, ItemActions::commentLimitReached(null));

$user = $burst(42, 21);
pin('a user is counted on their own', false, $user[0]);
pin('the 21st from the same user is refused', true, $user[20]);
pin('another user has their own count', false, ItemActions::commentLimitReached(43));

harness_section('add_comment() is wired to the checks');
$actions = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/actions/ItemActions.php');
preg_match('/public function add_comment\(\).*?\n    }\n/s', $actions, $m);
$body = $m[0] ?? '';
check(
    'a listing the visitor cannot see is refused',
    strpos($body, "ItemAccess::canView(\$aItem['item'], \$aItem['userId'], osc_is_admin_user_logged_in())") !== false
);
check('the limit is checked before the comment is stored', strpos($body, 'commentLimitReached($userId)') !== false
    && strpos($body, 'commentLimitReached($userId)') < strpos($body, '->insertGetId('));

$web = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php');
check(
    'the comment form 404s on a hidden listing, as the contact form does',
    (bool) preg_match("/case 'add_comment':.*?notFoundIfHidden\(\\\$item\);.*?add_comment\(\)/s", $web)
);
check('the controller explains the limit', strpos($web, "_m('Too many comments in an hour. Try again later.')") !== false);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
