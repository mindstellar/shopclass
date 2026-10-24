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
 * One of two posts of a rated comment by the same user at once, for
 * tests/api-comments-rating.php. Prints "won" when the comment was saved, "lost" when refused.
 *
 * Usage:  php tests/lib/comment-rating-child.php <database> <listing id> <user id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $listing, $user] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();
foreach (['enabled_users' => '1', 'enabled_comments' => '1', 'comment_ratings' => '1', 'moderate_comments' => '-1', 'reg_user_post_comments' => '0', 'language' => 'en_US'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
$_COOKIE['oc_userLocale'] = 'en_US';

try {
    (new \mindstellar\comment\CommentService())->post((int) $listing, ['body' => 'Same time', 'rating' => 4], \mindstellar\auth\Actor::user((int) $user));
    echo "won\n";
} catch (\mindstellar\exception\ConflictException $e) {
    echo "lost\n";
}
