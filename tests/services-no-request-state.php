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
const REQUEST_STATE = '/\bParams::|\bSession::getInstance\(|\bCookie::getInstance\(|\$_(SERVER|GET|POST|REQUEST|COOKIE|FILES|SESSION)\b|\bosc_(logged_user_id|logged_admin_id|is_web_user_logged_in|is_admin_user_logged_in)\(/';

/** A PHP file's code without its comments. */
function code_only(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
            $code .= str_repeat("\n", substr_count($token[1], "\n"));
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

harness_section('The pattern');
check('catches Params', preg_match(REQUEST_STATE, "Params::getParam('id')") === 1);
check('catches the session', preg_match(REQUEST_STATE, 'Session::getInstance()->_get("userId")') === 1);
check('catches a superglobal', preg_match(REQUEST_STATE, '$ip = $_SERVER["REMOTE_ADDR"];') === 1);
check('catches the signed-in user', preg_match(REQUEST_STATE, '$id = osc_logged_user_id();') === 1);
check('a comment does not count', preg_match(REQUEST_STATE, code_only("<?php\n/** A \$_FILES entry */\n// Params::x\n")) === 0);

harness_section('Services, policies, stores and queries');
$found = array();
$files = 0;
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . 'oc-includes/osclass/classes', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!preg_match('/(Service|Policy|Store|Query)\.php$/', $file->getFilename())) {
        continue;
    }
    $files++;
    foreach (explode("\n", code_only((string) file_get_contents($file->getPathname()))) as $i => $line) {
        if (preg_match(REQUEST_STATE, $line) === 1) {
            $found[] = substr($file->getPathname(), strlen(ABS_PATH)) . ':' . ($i + 1) . ': ' . trim($line);
        }
    }
}
check('the scan reads them', $files > 50, $files . ' files');
pin('none reads request state', array(), $found);

exit(harness_result());
