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
 * Pins UserActions::confirmEmailChange(): the code must match and be fresh, it is
 * cleared on use, a taken address is refused, and a failed write re-points nothing.
 *
 * Usage:  php tests/models/emailchange.php          (standalone, own scratch database)
 *         php tests/run-models.php emailchange      (as part of the suite)
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin  = scratchdb_session('osc_models_emailchange');
require_once __DIR__ . '/../lib/action-standins.php';
$prefix = DB_TABLE_PREFIX;

seed_locale($admin);
seed_country($admin, 'US', 'United States');
seed_currency($admin);
$category = seed_category($admin, 'Things');

$code  = 'c0de' . bin2hex(random_bytes(10));
$field = static function (string $sql) use ($admin) {
    $row = $admin->query($sql)->fetch_row();

    return $row === null ? null : $row[0];
};
$emailOf = static function (int $id) use ($field, $prefix) {
    return $field("SELECT s_email FROM {$prefix}t_user WHERE pk_i_id = $id");
};
/** A user with a pending change to $new, a listing, a comment and an alert. */
$pending = static function (string $name, string $new, string $issued = 'NOW()') use ($admin, $prefix, $code, $category): int {
    $id   = seed_user($admin, $name, $name . '@old.test');
    $item = seed_item($admin, $category, $id);
    $admin->query("UPDATE {$prefix}t_user SET s_pass_code = '$code', s_pass_date = $issued WHERE pk_i_id = $id");
    $admin->query("UPDATE {$prefix}t_item SET s_contact_email = '{$name}@old.test' WHERE pk_i_id = $item");
    $admin->query("INSERT INTO {$prefix}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, fk_i_user_id)
                   VALUES ($item, NOW(), 't', 'n', '{$name}@old.test', 'b', $id)");
    $admin->query("INSERT INTO {$prefix}t_alerts (s_email, fk_i_user_id, s_search, e_type) VALUES ('{$name}@old.test', $id, '', 'DAILY')");
    $admin->query("INSERT INTO {$prefix}t_user_email_tmp (fk_i_user_id, s_new_email, dt_date) VALUES ($id, '$new', NOW())");

    return $id;
};
$repointed = static function (int $id, string $email) use ($field, $prefix): array {
    return array(
        (int)$field("SELECT COUNT(*) FROM {$prefix}t_item WHERE fk_i_user_id = $id AND s_contact_email = '$email'"),
        (int)$field("SELECT COUNT(*) FROM {$prefix}t_item_comment WHERE fk_i_user_id = $id AND s_author_email = '$email'"),
        (int)$field("SELECT COUNT(*) FROM {$prefix}t_alerts WHERE fk_i_user_id = $id AND s_email = '$email'"),
    );
};

harness_section('A matching, fresh code switches everything');

$ann    = $pending('ann', 'ann@new.test');
$result = UserActions::confirmEmailChange($ann, $code);
pin('the change is applied', array('status' => 'ok', 'old' => 'ann@old.test', 'new' => 'ann@new.test'), $result);
pin('the user has the new address', 'ann@new.test', $emailOf($ann));
pin('listing, comment and alert follow it', array(1, 1, 1), $repointed($ann, 'ann@new.test'));
pin('the code is cleared', array(null, null), array(
    $field("SELECT s_pass_code FROM {$prefix}t_user WHERE pk_i_id = $ann"),
    $field("SELECT s_pass_date FROM {$prefix}t_user WHERE pk_i_id = $ann"),
));
pin('the pending row is gone', 0, (int)$field("SELECT COUNT(*) FROM {$prefix}t_user_email_tmp WHERE fk_i_user_id = $ann"));
pin('the same link does not work twice', 'invalid', UserActions::confirmEmailChange($ann, $code)['status']);
pin('nor does it work as a password-reset code', array(), User::newInstance()->findByIdPasswordSecret($ann, $code));

harness_section('Codes that must not work');

$ben = $pending('ben', 'ben@new.test');
pin('a wrong code is refused', 'invalid', UserActions::confirmEmailChange($ben, $code . 'x')['status']);
pin('an empty code is refused', 'invalid', UserActions::confirmEmailChange($ben, '')['status']);
pin('a numeric-looking code is refused', 'invalid', UserActions::confirmEmailChange($ben, '0')['status']);
pin('the right code for another user is refused', 'invalid', UserActions::confirmEmailChange($ann, $code)['status']);
pin('nothing changed for ben', 'ben@old.test', $emailOf($ben));

$ttl = User::PASS_CODE_TTL + 60;
$cid = $pending('cid', 'cid@new.test', "NOW() - INTERVAL $ttl SECOND");
pin('an expired code is refused', 'invalid', UserActions::confirmEmailChange($cid, $code)['status']);
pin('nothing changed for cid', 'cid@old.test', $emailOf($cid));

$dan = $pending('dan', 'dan@new.test');
$admin->query("UPDATE {$prefix}t_user SET b_enabled = 0 WHERE pk_i_id = $dan");
pin('a disabled account is refused', 'invalid', UserActions::confirmEmailChange($dan, $code)['status']);

$eve = $pending('eve', 'eve@new.test');
$admin->query("DELETE FROM {$prefix}t_user_email_tmp WHERE fk_i_user_id = $eve");
pin('with no pending change the link is refused', 'invalid', UserActions::confirmEmailChange($eve, $code)['status']);

harness_section('An address taken since the request');

$fay = $pending('fay', 'shared@new.test');
seed_user($admin, 'squatter', 'shared@new.test');
pin('the change is refused as taken', 'taken', UserActions::confirmEmailChange($fay, $code)['status']);
pin('fay keeps her address', 'fay@old.test', $emailOf($fay));
pin('nothing was re-pointed', array(0, 0, 0), $repointed($fay, 'shared@new.test'));

harness_section('A failed write inside the switch rolls it all back');

$gus = $pending('gus', 'gus@new.test');
$admin->query("CREATE TRIGGER {$prefix}test_block_comment BEFORE UPDATE ON {$prefix}t_item_comment
               FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked by test'");
check('the blocking trigger was created', $admin->errno === 0, $admin->error);
pin('the change reports a failure', 'failed', UserActions::confirmEmailChange($gus, $code)['status']);
$admin->query("DROP TRIGGER {$prefix}test_block_comment");
pin('the user keeps the old address', 'gus@old.test', $emailOf($gus));
pin('the listing was not re-pointed', array(0, 0, 0), $repointed($gus, 'gus@new.test'));
pin('the code still works once the fault is gone', 'ok', UserActions::confirmEmailChange($gus, $code)['status']);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/emailchange.php */
