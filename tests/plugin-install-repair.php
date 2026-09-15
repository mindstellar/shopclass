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
 * Pins Plugins::install() and the admin forms that change plugin and alert state.
 *
 * - A plugin left active but not installed (a crash between the two writes) installs on
 *   the next try instead of failing with a generic error forever.
 * - An error thrown by a plugin's install hook comes back as its message.
 * - No admin GET form targets an action that checks CSRF: GET forms carry no token, so
 *   the action rejects every submission.
 *
 * Usage: php tests/plugin-install-repair.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

$base = sys_get_temp_dir() . '/osc-plugin-install-' . getmypid() . '/';
@mkdir($base . 'healthy', 0777, true);
@mkdir($base . 'throws', 0777, true);
define('PLUGINS_PATH', $base);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

$GLOBALS['prefs'] = array('active_plugins' => serialize(array()), 'installed_plugins' => serialize(array()));

function osc_active_plugins()
{
    return $GLOBALS['prefs']['active_plugins'];
}

function osc_installed_plugins()
{
    return $GLOBALS['prefs']['installed_plugins'];
}

function osc_set_preference($name, $value)
{
    $GLOBALS['prefs'][$name] = $value;
}

function osc_reset_preferences()
{
}

function osc_plugins_path()
{
    return PLUGINS_PATH;
}

file_put_contents($base . 'healthy/index.php', '<?php');
file_put_contents(
    $base . 'throws/index.php',
    '<?php Plugins::addHook("install_throws/index.php", function () { throw new Error("needs ext-foo"); });'
);

$lists = static function (): array {
    return array(
        unserialize($GLOBALS['prefs']['active_plugins']),
        unserialize($GLOBALS['prefs']['installed_plugins']),
    );
};

harness_section('a clean install');

pin('install returns true', true, Plugins::install('healthy/index.php'));
pin('the plugin is active and installed', array(array('healthy/index.php'), array('healthy/index.php')), $lists());

harness_section('a plugin stuck active but not installed');

$GLOBALS['prefs']['active_plugins']    = serialize(array('healthy/index.php'));
$GLOBALS['prefs']['installed_plugins'] = serialize(array());

pin('install finishes the job', true, Plugins::install('healthy/index.php'));
pin('it is listed once in each list', array(array('healthy/index.php'), array('healthy/index.php')), $lists());
pin('a second install says it is already installed', array('error_code' => 'error_installed'), Plugins::install('healthy/index.php'));

harness_section('an install hook that throws an Error');

pin(
    'the message comes back instead of a fatal',
    array('error_code' => 'custom_error', 'msg' => 'needs ext-foo'),
    Plugins::install('throws/index.php')
);
check('the plugin is not activated', !in_array('throws/index.php', $lists()[0], true));

harness_section('admin GET forms never target a CSRF-checked action');

$checked = array();
foreach (glob(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdmin*.php') as $controller) {
    $page = strtolower(substr(basename($controller, '.php'), 6));
    $src  = (string) file_get_contents($controller);
    preg_match_all("/case\s*\(?\s*'([a-z_]+)'\s*\)?\s*:(.*?)(?=case\s*\(?\s*'|default\s*:|\z)/s", $src, $cases, PREG_SET_ORDER);
    foreach ($cases as $case) {
        if (strpos($case[2], 'osc_csrf_check()') !== false) {
            $checked[$page][$case[1]] = true;
        }
    }
}

$views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . 'oc-admin/themes/modern'));
foreach ($views as $file) {
    if ($file->getExtension() !== 'php' || strpos($file->getPathname(), '/scss/') !== false) {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    preg_match_all('/<form\b[^>]*method\s*=\s*["\']?get[^>]*>(.*?)<\/form>/is', $src, $forms, PREG_SET_ORDER);
    foreach ($forms as $form) {
        preg_match('/name="page"\s+value="([a-z_]+)"/', $form[1], $page);
        preg_match('/name="action"\s+value="([a-z_]*)"/', $form[1], $action);
        if (!$page) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen(ABS_PATH));
        // An empty action is filled in by script, so any checked action on that page could be sent.
        $hits = ($action[1] ?? '') === '' && isset($action[1])
            ? array_keys($checked[$page[1]] ?? array())
            : (isset($checked[$page[1]][$action[1] ?? '']) ? array($action[1]) : array());
        check($rel . ' GET form (page=' . $page[1] . ') targets no CSRF-checked action', $hits === array());
    }
}

array_map('unlink', glob($base . '*/index.php'));
array_map('rmdir', glob($base . '*'));
@rmdir($base);

exit(harness_result());
