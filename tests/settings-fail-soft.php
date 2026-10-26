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
 * Pins that a settings page core refuses takes down only that page when a plugin or
 * theme declared it: no throw, one log line, an admin notice. Core's own still throws.
 *
 * Usage:  php tests/settings-fail-soft.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

$root = sys_get_temp_dir() . '/osc-fail-soft-' . getmypid();
define('PLUGINS_PATH', $root . '/plugins/');
define('THEMES_PATH', $root . '/themes/');

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/base/Registry.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/category/CategoryModes.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/settings/SettingsPageRegistry.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/Store.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/PreferenceStore.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/TableStore.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/form/store/StoreFactory.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/FormSpec.php';

use mindstellar\settings\SettingsPageRegistry;

$GLOBALS['hooks'] = array();
$GLOBALS['flash'] = array();

require_once __DIR__ . '/lib/stubs.php';

function osc_add_hook($hook, $fn, $priority = 5)
{
    $GLOBALS['hooks'][$hook][] = $fn;
}

function osc_add_flash_warning_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flash'][] = array($section, $msg);
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';

$bad  = "array('title' => 'Bad', 'fields' => array(array('type' => 'url', 'name' => 'site', 'purify' => false)))";
$good = "array('title' => 'Good', 'fields' => array(array('type' => 'url', 'name' => 'site')))";
$files = array(
    'plugins/bad-plugin/index.php'   => "<?php osc_register_settings_page('bad-plugin', $bad);",
    'plugins/good-plugin/index.php'  => "<?php osc_register_settings_page('good-plugin', $good);",
    'plugins/built/index.php'        => "<?php osc_admin_form('built')->title('Built')->url('site')->purify(false)->register();",
    'themes/bad-theme/functions.php' => "<?php osc_register_settings_page('bad-theme', $bad);",
    'elsewhere/linked/index.php'     => "<?php osc_register_settings_page('linked', $bad);",
);
foreach ($files as $path => $code) {
    @mkdir(dirname($root . '/' . $path), 0777, true);
    file_put_contents($root . '/' . $path, $code);
}
symlink($root . '/elsewhere/linked', PLUGINS_PATH . 'linked');

$log = $root . '/error.log';
ini_set('error_log', $log);

/** Include a file, returning the exception message when it throws. */
function load_error(string $file): ?string
{
    try {
        include $file;
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
}

$registry = SettingsPageRegistry::getInstance();

harness_section('A plugin page core refuses');
pin('the plugin does not throw', null, load_error(PLUGINS_PATH . 'bad-plugin/index.php'));
pin('the page is not registered', null, $registry->get('bad-plugin'));
$lines = array_values(array_filter(explode("\n", (string)@file_get_contents($log))));
pin('the log gets one line', 1, count($lines));
check('the line names the plugin and the error', str_contains($lines[0] ?? '', 'plugin "bad-plugin"') && str_contains($lines[0] ?? '', 'purify'), $lines[0] ?? '');
pin('it is recorded as the plugin\'s', array('owner' => 'bad-plugin', 'kind' => 'plugin'), array_slice($registry->refused()['bad-plugin'] ?? array(), 0, 2));

harness_section('Other callers');
pin('a good plugin page still registers', 'Good', load_error(PLUGINS_PATH . 'good-plugin/index.php') ?? $registry->get('good-plugin')['title'] ?? null);
pin('the builder path does not throw', null, load_error(PLUGINS_PATH . 'built/index.php'));
pin('...and blames the plugin, not FormSpec', 'built', $registry->refused()['built']['owner'] ?? null);
pin('a theme page does not throw', null, load_error(THEMES_PATH . 'bad-theme/functions.php'));
pin('...and is recorded as the theme\'s', 'theme', $registry->refused()['bad-theme']['kind'] ?? null);
pin('a symlinked plugin does not throw', null, load_error(PLUGINS_PATH . 'linked/index.php'));
pin('...and is named by its folder', 'linked', $registry->refused()['linked']['owner'] ?? null);

$coreError = null;
try {
    osc_register_settings_page('core-bad', array('title' => 'Core', 'fields' => array(array('type' => 'url', 'name' => 'u', 'purify' => false))));
} catch (InvalidArgumentException $e) {
    $coreError = $e->getMessage();
}
check('a core caller still throws', $coreError !== null && str_contains($coreError, 'purify'), describe($coreError));
pin('...and nothing is recorded for it', false, isset($registry->refused()['core-bad']));

harness_section('The admin notice');
osc_settings_refused_notices();
pin('one admin warning per refused page', 4, count($GLOBALS['flash']));
pin('it goes to the admin section', 'admin', $GLOBALS['flash'][0][0] ?? null);
check('it names the plugin and the error', str_contains($GLOBALS['flash'][0][1] ?? '', 'bad-plugin plugin') && str_contains($GLOBALS['flash'][0][1] ?? '', 'purify'), $GLOBALS['flash'][0][1] ?? '');
check('a theme gets the theme wording', (bool)array_filter($GLOBALS['flash'], static fn ($f) => str_contains($f[1], 'bad-theme theme')));
check('the error text is escaped', !str_contains(implode('', array_column($GLOBALS['flash'], 1)), '"bad-plugin"'));

exec('rm -rf ' . escapeshellarg($root));

exit(harness_result());
