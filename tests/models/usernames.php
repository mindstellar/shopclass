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
 * Pins the username rules in UserActions: digit-only names are refused, the
 * username writes stay unique with and without a unique key on s_username, and a
 * claim that cannot get the lock writes nothing.
 *
 * Usage:  php tests/models/usernames.php          (standalone, own scratch database)
 *         php tests/run-models.php usernames      (as part of the suite)
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin  = scratchdb_session('osc_models_usernames');
// The model suite runs every file in one process, so set what registration depends on.
osc_set_preference('enabled_user_validation', '0');
osc_reset_preferences();
$prefix = DB_TABLE_PREFIX;

$usernameOf = static function (int $id) use ($admin, $prefix): string {
    return (string)$admin->query("SELECT s_username FROM {$prefix}t_user WHERE pk_i_id = $id")->fetch_assoc()['s_username'];
};
$assignDefault = static fn (int $id): string => \mindstellar\user\Usernames::assignDefault($id);

harness_section('UserActions::numericUsernameError');

check('an all-digit name is refused', UserActions::numericUsernameError('12345') !== '');
check('a single digit is refused', UserActions::numericUsernameError('0') !== '');
pin('a name with a letter passes', '', UserActions::numericUsernameError('user123'));
pin('a digit name with an underscore passes', '', UserActions::numericUsernameError('123_4'));
pin('an empty name is left to the caller', '', UserActions::numericUsernameError(''));

harness_section('UserActions::claimUsername');

$alice = seed_user($admin, 'alice', 'alice@example.test');
$bob   = seed_user($admin, 'bob', 'bob@example.test');

pin('a free name is claimed', 'ok', UserActions::claimUsername($bob, 'robert'));
pin('the name was written', 'robert', $usernameOf($bob));
pin('a name another user holds is refused', 'taken', UserActions::claimUsername($bob, 'alice'));
pin('the refused name was not written', 'robert', $usernameOf($bob));
pin('re-claiming your own name is fine', 'ok', UserActions::claimUsername($bob, 'robert'));

harness_section('Registration fallback: the id, or a suffix when it is taken');

// An older account already holds the next user id as its name.
$squatter = seed_user($admin, 'squatter', 'squatter@example.test');
$nextId   = $squatter + 1;
$admin->query("UPDATE {$prefix}t_user SET s_username = '$nextId' WHERE pk_i_id = $squatter");

$fresh = seed_user($admin, '_placeholder1', 'fresh@example.test');
pin('the fresh user got the expected id', $nextId, $fresh);
pin('the fallback adds a suffix when the id is taken', $nextId . '_2', $assignDefault($fresh));
pin('the suffixed name was written', $nextId . '_2', $usernameOf($fresh));

$plain = seed_user($admin, '_placeholder2', 'plain@example.test');
pin('with no clash the fallback is the id itself', (string)$plain, $assignDefault($plain));

harness_section('With a unique key on s_username');

$admin->query("ALTER TABLE {$prefix}t_user ADD UNIQUE KEY uk_test_username (s_username)");
check('the unique key was added', $admin->errno === 0, $admin->error);

pin('a taken name is still refused', 'taken', UserActions::claimUsername($alice, 'robert'));
pin('a free name is still claimed', 'ok', UserActions::claimUsername($alice, 'alicia'));

$third = seed_user($admin, '_placeholder3', 'third@example.test');
$admin->query("UPDATE {$prefix}t_user SET s_username = '" . ($third + 1) . "' WHERE pk_i_id = $alice");
$fourth = seed_user($admin, '_placeholder4', 'fourth@example.test');
pin('the fallback suffix still works under the key', $fourth . '_2', $assignDefault($fourth));

$admin->query("ALTER TABLE {$prefix}t_user DROP KEY uk_test_username");

harness_section('claimUsername fails closed when the lock is held elsewhere');
// A refused claim would wait 5 s for the lock each time; the refusal is the same at 0.
if (!defined('OSC_LOCK_WAIT')) {
    define('OSC_LOCK_WAIT', 0);
}

// The admin connection is its own session, so it can hold the lock the model connection waits on.
$lockName = 'osc_username_' . md5((defined('DB_NAME') ? DB_NAME : '') . $prefix . 't_user');
$holdLock = static function () use ($admin, $lockName): bool {
    return (int)$admin->query("SELECT GET_LOCK('" . $admin->real_escape_string($lockName) . "', 0) l")->fetch_assoc()['l'] === 1;
};
$dropLock = static function () use ($admin, $lockName): void {
    $admin->query("SELECT RELEASE_LOCK('" . $admin->real_escape_string($lockName) . "')");
};

check('the test holds the username lock', $holdLock());
pin('a claim that cannot get the lock fails', 'failed', UserActions::claimUsername($bob, 'bobby'));
pin('and writes nothing', 'robert', $usernameOf($bob));
$dropLock();
pin('with the lock free the same claim goes through', 'ok', UserActions::claimUsername($bob, 'bobby'));

harness_section('Registration with a chosen username claims it under the lock');

require_once __DIR__ . '/../lib/action-standins.php';

seed_locale($admin);
foreach (['enabled_users' => '1', 'enabled_user_registration' => '1'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
osc_reset_preferences();

$userCount = static function () use ($admin, $prefix): int {
    return (int)$admin->query("SELECT COUNT(*) c FROM {$prefix}t_user")->fetch_assoc()['c'];
};
$register = static function (string $username, string $email) {
    foreach (array('s_name', 's_email', 's_username', 's_password', 's_password2', 's_website', 's_info') as $stale) {
        Params::unsetParam($stale);
    }
    Params::setParam('s_name', 'Reg User');
    Params::setParam('s_email', $email);
    Params::setParam('s_username', $username);
    Params::setParam('s_password', 'correct horse battery');
    Params::setParam('s_password2', 'correct horse battery');

    return (new UserActions(false))->add();
};

pin('a free chosen name registers', 2, $register('newcomer', 'newcomer@example.test'));
pin('and the account holds that name', 'newcomer', (string)(User::getInstance()->findByEmail('newcomer@example.test')['s_username'] ?? ''));

$before = $userCount();
pin('a name another account holds is refused', "Username is already taken\n", $register('newcomer', 'second@example.test'));
pin('the half-created account is removed', $before, $userCount());
pin('the refused e-mail has no account', array(), User::getInstance()->findByEmail('second@example.test'));

check('the test holds the username lock again', $holdLock());
$before = $userCount();
pin(
    'a registration that cannot get the lock fails',
    "Your account could not be created. Please try again.\n",
    $register('lockedout', 'lockedout@example.test')
);
pin('and leaves no account behind', $before, $userCount());
$dropLock();

harness_section('A username held for a sign-up that made no account');

\mindstellar\user\Usernames::hold('Ghost');
check('a held name counts as taken, in any case', \mindstellar\user\UserStore::usernameTaken('ghost', 0) && \mindstellar\user\Usernames::held('GHOST'));
pin('a claim of a held name is refused', 'taken', \mindstellar\user\Usernames::claim($bob, 'ghost'));
pin('registering it is refused as for an account\'s name', "Username is already taken\n", $register('ghost', 'ghost@example.test'));
check('a name nobody holds is free', !\mindstellar\user\UserStore::usernameTaken('spectre', 0));
$admin->query("UPDATE {$prefix}t_key_value SET dt_expires = '2000-01-01 00:00:00' WHERE s_group = 'core.username_hold'");
check('a hold that has run out frees the name', !\mindstellar\user\UserStore::usernameTaken('ghost', 0));
$holdExpiry = static function () use ($admin, $prefix) {
    $row = $admin->query("SELECT dt_expires FROM {$prefix}t_key_value WHERE s_group = 'core.username_hold' ORDER BY dt_created DESC, s_key LIMIT 1")->fetch_row();

    return $row === null ? 'none' : $row[0];
};
osc_set_preference('enabled_user_validation', '1');
osc_set_preference('enabled_inactive_users', '1');
osc_set_preference('days_inactive_users', '7');
osc_reset_preferences();
$admin->query("DELETE FROM {$prefix}t_key_value WHERE s_group = 'core.username_hold'");
\mindstellar\user\Usernames::hold('wraith');
$expires = strtotime((string) $holdExpiry() . ' UTC');
check('with the cleanup of unactivated users on, a hold lasts as long as it keeps them (7 days)', abs($expires - (time() + 7 * 86400)) < 120, (string) $holdExpiry());
osc_set_preference('enabled_inactive_users', '0');
osc_reset_preferences();
$admin->query("DELETE FROM {$prefix}t_key_value WHERE s_group = 'core.username_hold'");
\mindstellar\user\Usernames::hold('wraith');
$expires = strtotime((string) $holdExpiry() . ' UTC');
check('with that cleanup off, a hold lasts 7 days', abs($expires - (time() + 7 * 86400)) < 120, (string) $holdExpiry());
osc_set_preference('enabled_inactive_users', '1');
osc_set_preference('days_inactive_users', '30');
osc_reset_preferences();
$admin->query("DELETE FROM {$prefix}t_key_value WHERE s_group = 'core.username_hold'");
\mindstellar\user\Usernames::hold('wraith');
$expires = strtotime((string) $holdExpiry() . ' UTC');
check('a 30-day cleanup period is capped at 7 days', abs($expires - (time() + 7 * 86400)) < 120, (string) $holdExpiry());
$holder = seed_user($admin, 'holder', 'holder@example.test');
pin('a held name is refused to a normal claim', 'taken', \mindstellar\user\Usernames::claim($holder, 'wraith'));
pin('an admin claim ignores the hold', 'ok', \mindstellar\user\Usernames::claim($holder, 'wraith', true));
pin('the admin\'s name was written', 'wraith', $usernameOf($holder));
osc_set_preference('enabled_user_validation', '0');
osc_reset_preferences();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/usernames.php */
