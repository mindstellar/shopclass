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
 * The shared helpers: the listing notice flash, the Actor from the session, the PHP user
 * name and the filtered iterator sync() and the update preflight both use.
 *
 * No database. Usage:  php tests/shared-helpers.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

$flashed = array();
if (!function_exists('osc_add_flash_error_message')) {
    function osc_add_flash_error_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashed'][] = array($section, $msg);
    }
}
if (!function_exists('osc_logged_admin_id')) {
    function osc_logged_admin_id()
    {
        return 7;
    }
}
if (!function_exists('osc_logged_user_id')) {
    function osc_logged_user_id()
    {
        return 9;
    }
}

use mindstellar\admin\SystemChecks;
use mindstellar\auth\Actor;
use mindstellar\listing\ListingNotices;

ListingNotices::flash(array('a', 'b'), true);
ListingNotices::flash(array('c'), false);
pin('notices go to the admin and the public channel', array(array('admin', 'a'), array('admin', 'b'), array('pubMessages', 'c')), $flashed);

$admin = Actor::fromSession(true);
check('the admin actor is the signed-in admin', $admin->isAdmin() && $admin->adminId() === 7 && $admin->userId() === null);
$user = Actor::fromSession(false);
check('the user actor is the signed-in user', !$user->isAdmin() && $user->userId() === 9);

pin('no id has no name', '', SystemChecks::userName(null));
pin('an unknown id is shown as #id', '#4000000', SystemChecks::userName(4000000));

$dir = sys_get_temp_dir() . '/osc-iter-' . bin2hex(random_bytes(4));
mkdir($dir . '/keep', 0755, true);
mkdir($dir . '/skip', 0755, true);
file_put_contents($dir . '/keep/a.php', '');
file_put_contents($dir . '/skip/b.php', '');
file_put_contents($dir . '/c.php', '');
$names = array();
foreach ((new mindstellar\utility\FileSystem())->filteredIterator($dir, array('skip'), RecursiveIteratorIterator::LEAVES_ONLY) as $f) {
    $names[] = $f->getBasename();
}
sort($names);
pin('the iterator leaves out the filtered names', array('a.php', 'c.php'), $names);
(new mindstellar\utility\FileSystem())->remove($dir);

harness_section('models on the new DB API');
$modelDir = __DIR__ . '/../oc-includes/osclass/classes/model/';
foreach (glob($modelDir . '*.php') as $file) {
    $src = (string) file_get_contents($file);
    if (strpos($src, 'namespace mindstellar\\model;') === false || basename($file) === 'Model.php') {
        continue;
    }
    $name = basename($file, '.php');
    check($name . ' extends Model', preg_match('/class ' . $name . ' extends Model\b/', $src) === 1);
    check($name . ' has no table() of its own', strpos($src, 'function table()') === false);
}

harness_section('plugin registries share one base');
$registries = [
    \mindstellar\widgets\WidgetRegistry::class,
    \mindstellar\theme\RenderTargetRegistry::class,
    \mindstellar\billing\FeatureRegistry::class,
    \mindstellar\billing\PaymentGatewayRegistry::class,
    \mindstellar\fields\FieldTypeRegistry::class,
    \mindstellar\pages\PageTemplateRegistry::class,
    \mindstellar\settings\SettingsPageRegistry::class,
];
$seen = [];
foreach ($registries as $class) {
    check($class . ' extends Registry', is_subclass_of($class, \mindstellar\base\Registry::class));
    check($class . ' instance() is shared', $class::instance() === $class::instance());
    check($class . ' instance() is its own class', get_class($class::instance()) === $class);
    $seen[spl_object_id($class::instance())] = true;
}
check('each registry has its own instance', count($seen) === count($registries));

harness_section('settings forms extend SettingsScreen');
$formDir = __DIR__ . '/../oc-includes/osclass/classes/admin/form/';
$formOverrides = array('MainSettingsScreen', 'MediaSettingsScreen', 'StorageSettingsScreen');
foreach (array('Advanced', 'Api', 'Comment', 'KeywordBlock', 'LatestSearch', 'MailServer', 'Main', 'Media', 'Permalink', 'Storage') as $form) {
    $name = $form . 'SettingsScreen';
    $src = (string) file_get_contents($formDir . $name . '.php');
    check($name . ' extends SettingsScreen', strpos($src, 'class ' . $name . ' extends SettingsScreen') !== false);
    check($name . ' has no register() of its own', strpos($src, 'function register(') === false);
    if (!in_array($name, $formOverrides, true)) {
        check($name . ' has no formVars() of its own', strpos($src, 'function formVars(') === false);
    }
}

harness_section('base classes are abstract');
foreach (array('mindstellar\\base\\Model', 'mindstellar\\base\\Registry', 'mindstellar\\base\\SettingsScreen') as $base) {
    check($base . ' is abstract', (new ReflectionClass($base))->isAbstract());
}

exit(harness_result());

/* file end: ./tests/shared-helpers.php */
