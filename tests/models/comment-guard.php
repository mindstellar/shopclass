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
 * Comments had no limit and were taken on listings the public cannot see. Now an address may
 * post 20 an hour (comment_post, adjustable with action_throttle_limit), and a listing that is
 * not live takes no comments from the public.
 *
 * Usage:  php tests/models/comment-guard.php          (standalone, own scratch database)
 *         php tests/run-models.php comment-guard      (as part of the suite)
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_comment_guard');

require_once __DIR__ . '/../lib/action-standins.php';

use mindstellar\auth\Actor;
use mindstellar\comment\CommentPolicy;

$admin->query('TRUNCATE TABLE ' . DB_TABLE_PREFIX . 't_rate_counter');
if (!function_exists('osc_item_url')) {
    function osc_item_url()
    {
        return 'http://localhost/item';
    }
}

seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$cat    = seed_category($admin, 'Comments');
$live   = seed_item($admin, $cat, null, 'Live listing');
$hidden = seed_item($admin, $cat, null, 'Hidden listing', 10.0, 0, 1);

osc_set_preference('enabled_comments', '1', 'osclass', 'BOOLEAN');
osc_set_preference('reg_user_post_comments', '0', 'osclass', 'BOOLEAN');
osc_set_preference('notify_new_comment', '0', 'osclass', 'BOOLEAN');
osc_set_preference('notify_new_comment_user', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_GET = array();
// Comment as a guest, whatever an earlier file in the suite left signed in.
Session::newInstance()->_drop('userId');
Session::newInstance()->_dropEphemeral('userId');
View::newInstance()->_erase('_loggedUser');

/** Post a comment on $itemId through ItemActions and return its status code. */
$post = static function (int $itemId): int {
    $_POST = $_REQUEST = array(
        'id' => (string) $itemId, 'authorName' => 'Ann', 'authorEmail' => 'ann@example.com',
        'title' => 'Hi', 'body' => 'Is it still for sale?',
    );
    Params::init();

    return (int) (new ItemActions(false))->add_comment();
};
$stored = static function (int $itemId) use ($admin): int {
    return (int) $admin->query('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . "t_item_comment WHERE fk_i_item_id = $itemId")
        ->fetch_row()[0];
};

harness_section('a listing that is not live');
pin('a guest comment is refused', -1, $post($hidden));
pin('...and nothing is stored', 0, $stored($hidden));

harness_section('the hourly comment limit');
$codes = array();
for ($i = 0; $i < 21; $i++) {
    $codes[] = $post($live);
}
check('20 comments are taken', count(array_filter(array_slice($codes, 0, 20), static function ($c) {
    return $c === 1 || $c === 2;
})) === 20);
pin('the 21st is refused with status 8', 8, $codes[20]);
pin('...and not stored', 20, $stored($live));

$admin->query('INSERT INTO ' . DB_TABLE_PREFIX . "t_user (dt_reg_date, s_name, s_username, s_password, s_secret, s_email, b_enabled, b_active) VALUES (NOW(), 'Sue', 'sue_cg', '', 'x', 'sue_cg@example.com', 1, 1)");
$sue = (int) $admin->insert_id;
Session::newInstance()->_setEphemeral('userId', (string) $sue);
$sueCodes = array();
for ($i = 0; $i < 21; $i++) {
    $sueCodes[] = $post($live);
}
check('a signed-in user on the same full address has their own count of 20', count(array_filter(array_slice($sueCodes, 0, 20), static function ($c) {
    return $c === 1 || $c === 2;
})) === 20);
pin('...and their 21st is refused with status 8', 8, $sueCodes[20]);
pin('another user has their own count', false, CommentPolicy::tooMany(Actor::user($sue + 1)));
Session::newInstance()->_dropEphemeral('userId');
View::newInstance()->_erase('_loggedUser');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_item_comment WHERE fk_i_user_id = $sue");
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_user WHERE pk_i_id = $sue");

$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
check('another address has its own count', in_array($post($live), array(1, 2), true));

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
osc_add_filter('action_throttle_limit', static function ($limit, $context) {
    return $context === 'comment_post' ? array('max' => 50, 'window' => 3600) : $limit;
});
check('action_throttle_limit can raise it', in_array($post($live), array(1, 2), true));

View::newInstance()->_erase('item');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
