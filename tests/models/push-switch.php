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
 * The browser-notifications switch: shipped off, on the users settings screen, added by migration 0087.
 * Usage: php tests/models/push-switch.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\admin\form\UserSettingsScreen;
use mindstellar\database\Connection;
use mindstellar\settings\SettingsPageRegistry;

$admin = api_admin_boot('osc_models_push_switch');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
$conn  = Connection::getInstance();
$p     = DB_TABLE_PREFIX;

$switch = static function () use ($admin, $p): ?string {
    $row = $admin->query("SELECT s_value FROM {$p}t_preference WHERE s_name = 'enabled_push'")->fetch_row();

    return $row ? (string) $row[0] : null;
};
$migrate = static function () use ($conn) {
    (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0087_push.php')->up($conn);
};

harness_section('fresh install');
check(
    'basic_data.sql ships the switch off',
    str_contains((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql'), "('osclass', 'enabled_push', '0', 'BOOLEAN')")
);

harness_section('migration 0087');
$admin->query("DELETE FROM {$p}t_preference WHERE s_name = 'enabled_push'");
pin('an upgraded site has no row', null, $switch());
$migrate();
pin('the migration adds it off', '0', $switch());
$admin->query("UPDATE {$p}t_preference SET s_value = '1' WHERE s_name = 'enabled_push'");
$migrate();
pin('a re-run leaves a switch turned on', '1', $switch());

harness_section('settings screen');
$fields = SettingsPageRegistry::getInstance()->fields(UserSettingsScreen::register());
check('the users page has the checkbox id enabled_push', isset($fields['enabled_push']) && ($fields['enabled_push']['id'] ?? '') === 'enabled_push');
check('with the HTTPS hint', str_contains((string) ($fields['enabled_push']['help'] ?? ''), 'Needs HTTPS'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
