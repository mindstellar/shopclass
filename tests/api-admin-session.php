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
 * The admin session an admin page token is checked against: read from a real PHP session file,
 * without a lock, a write or a new session.
 * Usage: php tests/api-admin-session.php
 */

define('OSC_CSRF_SECRET', 'api-admin-session-test-secret');
define('WEB_PATH', 'http://example.test/');

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\auth\MemoisedRows;
use mindstellar\api\identity\AdminSession;

$dir = sys_get_temp_dir() . '/osc-admin-session-' . getmypid();
mkdir($dir);
register_shutdown_function(static function () use ($dir): void {
    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});
ini_set('session.save_path', $dir);
ini_set('session.use_cookies', '0');

$saved = static function (array $values): string {
    session_id(session_create_id());
    session_start();
    $_SESSION = $values;
    $id       = session_id();
    session_write_close();
    $_SESSION = [];

    return $id;
};
$admins = new MemoisedRows(static fn (int $id): ?array => $id === 3 ? ['pk_i_id' => '3', 's_username' => 'ada', 'i_auth_stamp' => '2'] : null);
$read   = static function (?string $cookie) use ($admins): ?array {
    $_COOKIE = $cookie === null ? [] : ['osclass' => $cookie];

    return AdminSession::admin($admins);
};

$signedIn = $saved(['adminId' => 3, 'adminStamp' => 2]);
$file     = $dir . '/sess_' . $signedIn;
$before   = [file_get_contents($file), filemtime($file)];
pin('the session names the admin', '3', $read($signedIn)['pk_i_id'] ?? null);
check('the session is closed again', session_status() === PHP_SESSION_NONE);
pin('and was not written', $before, [file_get_contents($file), filemtime($file)]);
pin('a stale sign-out stamp is refused', null, $read($saved(['adminId' => 3, 'adminStamp' => 1])));
pin('a session from before stamps is refused', null, $read($saved(['adminId' => 3])));
pin('an admin that no longer exists is refused', null, $read($saved(['adminId' => 4, 'adminStamp' => 2])));
pin('a web user\'s session is no admin', null, $read($saved(['userId' => 3])));
pin('no session cookie, no admin', null, $read(null));
pin('a malformed session id is never opened', null, $read('../../etc/passwd'));
$known = glob($dir . '/sess_*') ?: [];
pin('an unknown session id is no admin', null, $read(str_repeat('a', 26)));
pin('and stores nothing', [''], array_values(array_map('file_get_contents', array_diff(glob($dir . '/sess_*') ?: [], $known))));

exit(harness_result());
