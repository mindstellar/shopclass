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
 * A cookie never authenticates an API call: WebIdentity::forget() drops the user and admin
 * identity the bootstrap resolved from cookies or the session, for this request only, and
 * index.php runs it for page=api before maintenance, the user switch and lastAccess.
 *
 * DB-free.  Usage: php tests/api-web-identity.php
 */

define('WEB_PATH', 'http://example.test/');

// A stored session from an older login carries an identity. It is started before any output,
// as a web request would, so the session can really be active.
$sessionDir = sys_get_temp_dir() . '/api-web-identity-' . getmypid();
@mkdir($sessionDir, 0700);
ini_set('session.save_path', $sessionDir);
ini_set('session.use_strict_mode', '0');
$sessionId = 'apitestsession0000000000000001';
file_put_contents($sessionDir . '/sess_' . $sessionId, 'userId|i:10;adminId|i:1;other|s:4:"kept";');
$_COOKIE = ['osclass' => $sessionId];

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\identity\WebIdentity;

$stored = Session::getInstance();
$stored->session_resume();
$activeBefore = session_status() === PHP_SESSION_ACTIVE;
$idBefore     = $stored->_get('userId');
WebIdentity::forget();
$activeAfter  = session_status() === PHP_SESSION_ACTIVE;
$idAfter      = $stored->_get('userId');
$sessionAfter = $_SESSION;
$stored->session_destroy();
$stored->_set('later', 1);
$idAfterRestart = $stored->_get('userId');
$fileAfter      = (string) file_get_contents($sessionDir . '/sess_' . $sessionId);
array_map('unlink', glob($sessionDir . '/*') ?: []);
@rmdir($sessionDir);

harness_section('an active session');
pin('the stored session was active and named the user', [true, 10], [$activeBefore, $idBefore]);
pin('forget() closes it', false, $activeAfter);
pin('the user is gone for this request', '', $idAfter);
pin('and from $_SESSION, while other keys stay', [false, false, 'kept'], [isset($sessionAfter['userId']), isset($sessionAfter['adminId']), $sessionAfter['other'] ?? null]);
pin('a later destroy and restart does not bring it back', '', $idAfterRestart);
check('the stored session is not rewritten, so the browser stays logged in', str_contains($fileAfter, 'userId|i:10;'));

$_COOKIE = ['oc_userId' => '10', 'oc_userSecret' => 's', 'oc_adminId' => '1', 'oc_adminSecret' => 'a', 'other' => 'kept'];
$session = Session::getInstance();
$session->_setEphemeral('userId', 10);
$session->_setEphemeral('userEmail', 'u@x.test');
$session->_setEphemeral('adminId', 1);
View::getInstance()->_exportVariableToView('_loggedUser', ['pk_i_id' => 10, 'b_enabled' => 1, 'b_active' => 1]);

WebIdentity::forget();

harness_section('forget()');
pin('the user id is gone', '', $session->_get('userId'));
pin('the e-mail is gone', '', $session->_get('userEmail'));
pin('the admin id is gone', '', $session->_get('adminId'));
pin('the identity cookies are gone from this request', [], array_values(array_intersect(array_keys($_COOKIE), WebIdentity::COOKIES)));
pin('other cookies stay', 'kept', $_COOKIE['other'] ?? null);
pin('the logged user reads as nobody, so it is not looked up again', [], View::getInstance()->_get('_loggedUser'));
check('nobody is not a logged-in user', !isset(View::getInstance()->_get('_loggedUser')['b_enabled']));
check('no session was started', session_status() !== PHP_SESSION_ACTIVE);

harness_section('index.php');
$index = (string) file_get_contents(ABS_PATH . 'index.php');
$at    = static fn (string $needle): int => (int) strpos($index, $needle);
$boot  = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/api/boot.php');
check('page=api forgets the web identity', str_contains($index, "if (\$osc_api_request) {\n    \\mindstellar\\apiaccess\\ApiAccess::begin();"));
check('which is WebIdentity::forget()', str_contains($boot, 'static fn () => \\mindstellar\\api\\identity\\WebIdentity::forget(),'));
check('before the maintenance check', $at('ApiAccess::begin()') > 0 && $at('ApiAccess::begin()') < $at("file_exists(ABS_PATH . '.maintenance')"));
check('an admin cookie does not lift maintenance for the API', str_contains($index, '!$osc_api_request && osc_is_admin_user_logged_in(),'));
check('an API call under maintenance gets problem+json', str_contains($index, "if (\$osc_api_request) {\n            \\mindstellar\\apiaccess\\ApiAccess::maintenance();")
    && str_contains($boot, 'static fn () => \\mindstellar\\api\\Problem::maintenance()->send()'));
check('an API call never records last access', str_contains($index, 'if (!$osc_api_request && osc_is_web_user_logged_in()) {'));
check('nor touches the user cookies when users are off', str_contains($index, 'if (!$osc_api_request && !osc_users_enabled() && osc_is_web_user_logged_in()) {'));

exit(harness_result());
