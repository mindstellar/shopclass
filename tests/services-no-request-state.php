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
 * Services, policies, stores and queries never read the request, the session, cookies or the
 * signed-in user. They get what they need from an …Input class or an Actor, so the web and the
 * API call them the same way.
 *
 * DB-free.  Usage: php tests/services-no-request-state.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

/** Code that reads request state, matched in a file with its comments taken out. */
const REQUEST_STATE = '/\bParams::|\b(Session|Cookie)::(getInstance|newInstance)\s*\(|\$_(SERVER|GET|POST|REQUEST|COOKIE|FILES|SESSION|ENV)\b|\bfilter_input(_array)?\s*\(|\bgetallheaders\s*\(|\bosc_(logged_(user|admin)(_(id|email|name|username|phone))?|is_web_user_logged_in|is_admin_user_logged_in)\s*\(/';

harness_section('The pattern');
check('catches Params', preg_match(REQUEST_STATE, "Params::getParam('id')") === 1);
check('catches the session', preg_match(REQUEST_STATE, 'Session::getInstance()->_get("userId")') === 1);
check('catches a superglobal', preg_match(REQUEST_STATE, '$ip = $_SERVER["REMOTE_ADDR"];') === 1);
check('catches the signed-in user, however written', preg_match(REQUEST_STATE, '$id = osc_logged_user_id ();') === 1 && preg_match(REQUEST_STATE, '$u = osc_logged_user();') === 1);
check('catches the other ways in', preg_match(REQUEST_STATE, 'Session::newInstance()') === 1 && preg_match(REQUEST_STATE, 'filter_input(INPUT_GET, "a")') === 1 && preg_match(REQUEST_STATE, '$_ENV["X"]') === 1);
check('a comment does not count', preg_match(REQUEST_STATE, harness_code_only("<?php\n/** A \$_FILES entry */\n// Params::x\n")) === 0);

harness_section('Services, policies, stores and queries');
$found = array();
$files = harness_class_files('oc-includes/osclass/classes', static fn (string $path): bool => preg_match('/(Service|Policy|Store|Query)\.php$/', $path) === 1);
foreach ($files as $path => $source) {
    foreach (explode("\n", harness_code_only($source)) as $i => $line) {
        if (preg_match(REQUEST_STATE, $line) === 1) {
            $found[] = $path . ':' . ($i + 1) . ': ' . trim($line);
        }
    }
}
check('the scan reads them', count($files) > 50, count($files) . ' files');
pin('none reads request state', array(), $found);

exit(harness_result());
