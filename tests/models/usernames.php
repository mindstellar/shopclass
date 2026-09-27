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
 * Pins the username rules in UserActions: digit-only names are refused, and the
 * username writes stay unique with and without a unique key on s_username.
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
$prefix = DB_TABLE_PREFIX;

$usernameOf = static function (int $id) use ($admin, $prefix): string {
    return (string)$admin->query("SELECT s_username FROM {$prefix}t_user WHERE pk_i_id = $id")->fetch_assoc()['s_username'];
};
$assignDefault = static function (int $id): string {
    $method = new ReflectionMethod('UserActions', 'assignDefaultUsername');
    $method->setAccessible(true);

    return $method->invoke(null, $id);
};

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

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/usernames.php */
