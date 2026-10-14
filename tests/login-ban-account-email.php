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
 * Pins that the web sign-in checks the ban rules against the account's own e-mail, not the
 * name that was typed. The form takes an e-mail address or a username, so checking the typed
 * value let a banned address sign in by its username.
 *
 * A source scan, so no bootstrap.  DB-free.  Usage: php tests/login-ban-account-email.php
 */

require_once __DIR__ . '/lib/harness.php';

$src = (string) file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/controller/CWebLogin.php');

// The login action: from its case label to the next one.
$start = (int) strpos($src, "case ('login_post')");
$end   = strpos($src, 'case (', $start + 1);
$block = substr($src, $start, $end === false ? null : $end - $start);

harness_section('web sign-in ban check');

check('the login action was found to scan', $start > 0 && strpos($block, 'osc_is_banned(') !== false);
check(
    'the ban check uses the account e-mail',
    preg_match('/osc_is_banned\(\s*(?:\(string\)\s*)?\$user\[\s*[\'"]s_email[\'"]\s*\]/', $block) === 1
);
check(
    '...not the e-mail or username as typed',
    preg_match('/osc_is_banned\(\s*\$email\s*\)/', $block) !== 1
);
check(
    '...and runs after the account was found and its password verified',
    strpos($block, 'osc_is_banned(') > (int) strpos($block, 'osc_verify_password(')
);

exit(harness_result());
