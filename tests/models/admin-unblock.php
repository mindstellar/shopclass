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
 * The Unblock button on System info > Security, posted to the real controller branch
 * (settings, login_throttle_unblock) with a valid CSRF token. An IPv6 sign-in is listed
 * by its /64, so both the listed "…::/64" value and a bare address in it must clear it.
 *
 * Usage:  php tests/models/admin-unblock.php      (standalone, own scratch database)
 *         php tests/run-models.php admin-unblock  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_admin_unblock');

require_once __DIR__ . '/../lib/action-standins.php';
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
foreach (array('hMessages', 'hSecurity') as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}
if (!defined('OSC_DEBUG')) {
    define('OSC_DEBUG', false);
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'http://localhost/oc-admin/' . ($index ? 'index.php' : '');
    }
}

/** Thrown in place of the exit() a real redirect ends the request with. */
class UnblockRedirect extends RuntimeException
{
}

/** The real controller, with a redirect that returns control to the test. */
class TestSpamnBots extends CAdminSettingsSpamnBots
{
    public function redirectTo($url, $code = null)
    {
        throw new UnblockRedirect((string) $url);
    }
}

$table = DB_TABLE_PREFIX . 't_login_attempt';

/** Rows stored under an address bucket, seeded with raw mysqli. */
$seed = static function (string $bucket, int $count) use ($admin, $table): void {
    $date = date('Y-m-d H:i:s', time() - 60);
    for ($i = 0; $i < $count; $i++) {
        seed_exec($admin, "INSERT INTO $table (s_context, s_account, s_ip, dt_date) VALUES ('web', ?, ?, ?)", 'sss', array('u' . $i . '@example.invalid', $bucket, $date));
    }
};
$rows = static function (string $bucket) use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) FROM $table WHERE s_ip = '" . $admin->real_escape_string($bucket) . "'")->fetch_row()[0];
};

/** POST the Unblock form for one address; returns the redirect target. */
$unblock = static function (string $ip): string {
    $csrf  = new \mindstellar\security\Csrf();
    $_GET  = array();
    $_POST = $_REQUEST = array(
        'page' => 'settings', 'action' => 'login_throttle_unblock', 'ip' => $ip,
        'CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue(),
    );
    Params::init();
    $ctl    = (new ReflectionClass('TestSpamnBots'))->newInstanceWithoutConstructor();
    $action = new ReflectionProperty('BaseModel', 'action');
    $action->setAccessible(true);
    $action->setValue($ctl, 'login_throttle_unblock');
    try {
        $ctl->doModel();
    } catch (UnblockRedirect $e) {
        return $e->getMessage();
    }

    return '';
};

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$admin->query("TRUNCATE TABLE $table");

harness_section('Unblock posts for an IPv6 /64');

$seed('2001:db8:5:6::/64', 3);
$seed('203.0.113.77', 2);
$to = $unblock('2001:db8:5:6::/64');
pin('the listed /64 value clears its rows', 0, $rows('2001:db8:5:6::/64'));
pin('...and leaves another address alone', 2, $rows('203.0.113.77'));
check('...and returns to System info > Security', str_contains($to, 'tab=security'));

$seed('2001:db8:5:6::/64', 3);
$unblock('2001:db8:5:6:abcd::1');
pin('a bare address inside the /64 clears it too', 0, $rows('2001:db8:5:6::/64'));

$seed('2001:db8:5:6::/64', 3);
$unblock('2001:db8:5:6::/63');
pin('a value that is not an address or a /64 clears nothing', 3, $rows('2001:db8:5:6::/64'));

$admin->query("TRUNCATE TABLE $table");

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/admin-unblock.php */
