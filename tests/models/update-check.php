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
 * Update-check results live in the `update_check` key-value group, not in t_preference,
 * which every request loads. The old preference readers still answer, and a save drops the
 * old preference rows.
 *
 * Usage:  php tests/models/update-check.php        (standalone, own scratch database)
 *         php tests/run-models.php update-check    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_update_check');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hKv.php';

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/functions.php';

$prefs = DB_TABLE_PREFIX . 't_preference';
$count = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];

harness_section('state');
pin('nothing checked reads as never checked', 0, osc_update_check_state('plugins')['checked']);
pin('and as no pending updates', array(), osc_update_check_state('plugins')['to_update']);

$admin->query("DELETE FROM $prefs WHERE s_name IN ('plugins_to_update', 'plugins_update_count', 'plugins_last_version_check', 'update_core_json', 'last_version_check')");
$admin->query("INSERT INTO $prefs VALUES ('osclass', 'plugins_to_update', '[\"x\"]', 'STRING'), ('osclass', 'plugins_update_count', '1', 'STRING'), ('osclass', 'plugins_last_version_check', '5', 'STRING')");
osc_reset_preferences();

$now = time();
osc_update_check_save('plugins', array('checked' => $now, 'count' => 2, 'to_update' => array('a', 'b'), 'downloaded' => array('a', 'b', 'c')));
pin('a save lands in the update_check group', 2, osc_kv_get('update_check', 'plugins')['count']);
pin('and drops the old preference rows', 0, $count("SELECT COUNT(*) FROM $prefs WHERE s_name LIKE 'plugins\\_%'"));
pin('the saved list reads back', array('a', 'b'), osc_update_check_state('plugins')['to_update']);

harness_section('readers');
pin('osc_plugins_last_version_check forwards', $now, osc_plugins_last_version_check());
pin('osc_check_plugins_update returns the saved count', 2, osc_check_plugins_update());
pin('a fresh check schedules no footer re-check', false, in_array('check_plugins_admin_footer', Plugins::callbacks('admin_footer'), true));
osc_update_check_save('themes', array('checked' => $now - 2 * 86400, 'count' => 1, 'to_update' => array('t')));
pin('a day-old check still returns its count', 1, osc_check_themes_update());
pin('and schedules the footer re-check', true, in_array('check_themes_admin_footer', Plugins::callbacks('admin_footer'), true));

$package = array('s_new_version' => '99.0.0', 's_source_url' => 'https://example.test/x.zip');
$admin->query("INSERT INTO $prefs VALUES ('osclass', 'update_core_json', '{}', 'STRING'), ('osclass', 'last_version_check', '7', 'STRING')");
osc_reset_preferences();
osc_update_check_save('core', array('checked' => $now, 'available' => true, 'package' => $package));
pin('osc_update_core_json returns the package as JSON', $package, json_decode(osc_update_core_json(), true));
pin('osc_last_version_check forwards', $now, osc_last_version_check());
pin('the core rows leave preferences', 0, $count("SELECT COUNT(*) FROM $prefs WHERE s_name IN ('update_core_json', 'last_version_check')"));
osc_update_check_save('core', array('checked' => $now));
pin('no package reads as an empty string', '', osc_update_core_json());

harness_section('sources');
foreach (array(
    'oc-includes/osclass/classes/admin/ajax/UpdateAjax.php',
    'oc-includes/osclass/classes/upgrade/Osclass.php',
    'oc-includes/osclass/classes/location/LocationCatalog.php',
    'oc-includes/osclass/classes/controller/admin/CAdminPlugins.php',
    'oc-includes/osclass/classes/controller/admin/CAdminLanguages.php',
    'oc-admin/themes/modern/appearance/index.php',
) as $file) {
    $src = (string) file_get_contents(ABS_PATH . $file);
    check(basename($file) . ' keeps no update-check state in preferences', !preg_match(
        "/osc_(get|set)_preference\('(update_core_json|update_core_available|last_version_check|location_catalog_checked|[a-z]+_(to_update|update_count|last_version_check))'/",
        $src
    ));
}
$backup = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/backup/BackupService.php');
check('the backup probe is cached in the key-value store', strpos($backup, "osc_kv_set('backup', 'probe'") !== false
    && strpos($backup, "osc_set_preference('backup_probe'") === false);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
