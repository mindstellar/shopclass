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
 * Migration 0047 maps the old prerelease switch to a channel and keeps what an admin set, and
 * the automatic update claim lets exactly one run through per version.
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\upgrade\AutoSecurityUpdate;

$admin = scratchdb_session('osc_models_updatechannel');
$table = DB_TABLE_PREFIX . 't_preference';
$conn  = Connection::instance();
$pref  = static function (string $name) use ($admin, $table) {
    $row = $admin->query("SELECT s_value FROM $table WHERE s_section = 'osclass' AND s_name = '$name'")->fetch_row();

    return $row === null ? null : $row[0];
};
$reset = static function (?string $old) use ($admin, $table) {
    $admin->query("DELETE FROM $table WHERE s_name IN ('allow_update_prerelease', 'update_channel', 'auto_security_updates', 'auto_update_tried')");
    if ($old !== null) {
        $admin->query("INSERT INTO $table VALUES ('osclass', 'allow_update_prerelease', '$old', 'BOOLEAN')");
    }
};
$migrate = static function () use ($conn) {
    (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0047_update_channel.php')->up($conn);
};

harness_section('migration 0047');

foreach (array('1' => 'beta', '0' => 'stable', null => 'stable') as $old => $channel) {
    $reset($old === '' ? null : (string) $old);
    $migrate();
    pin('prerelease ' . ($old === '' ? 'never set' : $old) . ' becomes ' . $channel, $channel, $pref('update_channel'));
}
pin('automatic security installs start off', '0', $pref('auto_security_updates'));
$admin->query("UPDATE $table SET s_value = 'rc' WHERE s_name = 'update_channel'");
$migrate();
pin('a re-run keeps the channel an admin chose', 'rc', $pref('update_channel'));

harness_section('the automatic update claim');

$claim = new ReflectionMethod(AutoSecurityUpdate::class, 'claim');
$claim->setAccessible(true);
$admin->query("DELETE FROM $table WHERE s_name = 'auto_update_tried'");
pin('the first run claims 6.4.2', true, $claim->invoke(null, '6.4.2'));
pin('a second run for 6.4.2 does not', false, $claim->invoke(null, '6.4.2'));
pin('and the version is recorded', '6.4.2', $pref('auto_update_tried'));
pin('a later release can be claimed', true, $claim->invoke(null, '6.4.3'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
