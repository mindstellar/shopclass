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
define('PLUGINS_PATH', $root . '/oc-content/plugins/');
define('THEMES_PATH', $root . '/oc-content/themes/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
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

$GLOBALS['flash'] = array();

require_once __DIR__ . '/lib/stubs.php';

function osc_plugins_path()
{
    return PLUGINS_PATH;
}

function osc_active_plugins()
{
    return serialize(array('linked/index.php'));
}

function osc_add_hook($hook, $fn, $priority = 5)
{
    Plugins::addHook($hook, $fn, $priority);
}

function osc_add_flash_warning_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flash'][] = array($section, $msg);
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';

$bad  = "array('title' => 'Bad', 'fields' => array(array('type' => 'url', 'name' => 'site', 'purify' => false)))";
$good = "array('title' => 'Good', 'fields' => array(array('type' => 'url', 'name' => 'site')))";
$files = array(
    'oc-content/plugins/bad-plugin/index.php'   => "<?php osc_register_settings_page('bad-plugin', $bad);",
    'oc-content/plugins/other-plugin/index.php' => "<?php osc_register_settings_page('bad-plugin', $bad);",
    'oc-content/plugins/good-plugin/index.php'  => "<?php osc_register_settings_page('good-plugin', $good);",
    'oc-content/plugins/built/index.php'        => "<?php osc_admin_form('built')->title('Built')->url('site')->purify(false)->register();",
    'oc-content/plugins/later/index.php'        => "<?php osc_add_hook('init', static function () { osc_register_settings_page('later', $bad); });",
    'oc-content/themes/bad-theme/functions.php' => "<?php osc_register_settings_page('bad-theme', $bad);",
    'elsewhere/real-folder/index.php'           => "<?php osc_register_settings_page('linked', $bad);",
    'oc-content/uploads/stray.php'              => "<?php osc_register_settings_page('stray', $bad);",
    'oc-content/plugins/nested/index.php'       => "<?php \$GLOBALS['inner'] = Plugins::currentOwner(); Plugins::includeAs('theme', 'inner-theme', __DIR__ . '/inner.php'); \$GLOBALS['after'] = Plugins::currentOwner();",
    'oc-content/plugins/nested/inner.php'       => "<?php \$GLOBALS['deep'] = Plugins::currentOwner();",
    'oc-content/plugins/thrower/index.php'      => "<?php throw new RuntimeException('boom');",
);
foreach ($files as $path => $code) {
    @mkdir(dirname($root . '/' . $path), 0777, true);
    file_put_contents($root . '/' . $path, $code);
}
symlink($root . '/elsewhere/real-folder', PLUGINS_PATH . 'linked');

$log = $root . '/error.log';
ini_set('error_log', $log);

/** Run $load, returning the exception message when it throws. */
function load_error(callable $load): ?string
{
    try {
        $load();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
}

/** Load a plugin's index.php the way core does. */
function load_plugin(string $slug): ?string
{
    return load_error(static fn () => Plugins::includeAs('plugin', $slug, PLUGINS_PATH . $slug . '/index.php'));
}

/** The refusal recorded for $owner's page $id, or null. */
function refusal(string $kind, string $owner, string $id): ?array
{
    return SettingsPageRegistry::getInstance()->refused()[$kind . ':' . $owner . ':' . $id] ?? null;
}

$registry = SettingsPageRegistry::getInstance();

harness_section('A plugin page core refuses');
pin('the plugin does not throw', null, load_plugin('bad-plugin'));
pin('the page is not registered', null, $registry->get('bad-plugin'));
$lines = array_values(array_filter(explode("\n", (string)@file_get_contents($log))));
pin('the log gets one line', 1, count($lines));
check('the line names the plugin and the error', str_contains($lines[0] ?? '', 'plugin "bad-plugin"') && str_contains($lines[0] ?? '', 'purify'), $lines[0] ?? '');
pin('it is recorded as the plugin\'s', array('id' => 'bad-plugin', 'owner' => 'bad-plugin', 'kind' => 'plugin'), array_slice(refusal('plugin', 'bad-plugin', 'bad-plugin') ?? array(), 0, 3));
pin('owner is back to core after the include', null, Plugins::currentOwner());
pin('a second plugin refusing the same id is kept too', 'other-plugin', (load_plugin('other-plugin') ?? refusal('plugin', 'other-plugin', 'bad-plugin')['owner'] ?? null));

harness_section('Other callers');
pin('a good plugin page still registers', 'Good', load_plugin('good-plugin') ?? $registry->get('good-plugin')['title'] ?? null);
pin('the builder path does not throw', null, load_plugin('built'));
pin('...and blames the plugin, not FormSpec', 'built', refusal('plugin', 'built', 'built')['owner'] ?? null);
pin('a theme page does not throw', null, load_error(static fn () => Plugins::includeAs('theme', 'bad-theme', THEMES_PATH . 'bad-theme/functions.php')));
pin('...and is recorded as the theme\'s', 'theme', refusal('theme', 'bad-theme', 'bad-theme')['kind'] ?? null);
pin('a symlinked plugin core loads as active does not throw', null, load_error(static fn () => Plugins::loadActive()));
pin('...and is named by its link, not the real folder', 'linked', refusal('plugin', 'linked', 'linked')['owner'] ?? null);

pin('a hook the plugin added loads without throwing', null, load_plugin('later'));
pin('...and when the hook runs later the page is still the plugin\'s', null, load_error(static fn () => Plugins::runHook('init')));
pin('...recorded under the plugin', 'later', refusal('plugin', 'later', 'later')['owner'] ?? null);
pin('...and the owner is cleared after the hook', null, Plugins::currentOwner());

load_plugin('nested');
pin('the include sets the owner', array('plugin', 'nested'), $GLOBALS['inner'] ?? null);
pin('a nested include sets its own', array('theme', 'inner-theme'), $GLOBALS['deep'] ?? null);
pin('...and the outer one is back after it', array('plugin', 'nested'), $GLOBALS['after'] ?? null);
try {
    Plugins::includeAs('plugin', 'thrower', PLUGINS_PATH . 'thrower/index.php');
} catch (RuntimeException $e) {
}
pin('a plugin that throws does not leave its owner set', null, Plugins::currentOwner());

$stray = load_error(static fn () => include $root . '/oc-content/uploads/stray.php');
check('a file under oc-content/ that core did not load as a plugin throws', $stray !== null && str_contains($stray, 'purify'), describe($stray));

$coreError = load_error(static fn () => osc_register_settings_page('core-bad', array('title' => 'Core', 'fields' => array(array('type' => 'url', 'name' => 'u', 'purify' => false)))));
check('a core caller still throws', $coreError !== null && str_contains($coreError, 'purify'), describe($coreError));
Plugins::addHook('core_hook', static function () {
    osc_register_settings_page('core-hook', array('title' => 'Core', 'fields' => array(array('type' => 'url', 'name' => 'u', 'purify' => false))));
});
$hookError = load_error(static fn () => Plugins::runHook('core_hook'));
check('a hook callback core added (no owner) still throws', $hookError !== null && str_contains($hookError, 'purify'), describe($hookError));
$emptyError = load_error(static fn () => call_user_func('osc_register_settings_page', 'empty-bad', array('title' => 'E', 'fields' => array(array('type' => 'url', 'name' => 'u', 'purify' => false)))));
check('a caller with no file (an internal call) counts as core and throws', $emptyError !== null, describe($emptyError));
pin('...and nothing is recorded for them', array(), array_filter(array_keys($registry->refused()), static fn ($k) => preg_match('/:(core-bad|core-hook|empty-bad|stray)$/', $k) === 1));

harness_section('The admin notice');
check('core hooks it on admin_page_header, for every admin theme', in_array('osc_settings_refused_notices', Plugins::callbacks('admin_page_header'), true));
osc_settings_refused_notices();
pin('one admin warning per refused page', 6, count($GLOBALS['flash']));
pin('it goes to the admin section', 'admin', $GLOBALS['flash'][0][0] ?? null);
check('it names the plugin and the error', str_contains($GLOBALS['flash'][0][1] ?? '', 'bad-plugin plugin') && str_contains($GLOBALS['flash'][0][1] ?? '', 'purify'), $GLOBALS['flash'][0][1] ?? '');
check('a theme gets the theme wording', (bool)array_filter($GLOBALS['flash'], static fn ($f) => str_contains($f[1], 'bad-theme theme')));
check('the error text is escaped', !str_contains(implode('', array_column($GLOBALS['flash'], 1)), '"bad-plugin"'));

exec('rm -rf ' . escapeshellarg($root));

exit(harness_result());
