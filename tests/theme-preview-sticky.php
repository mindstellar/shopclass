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
 * Pins who may preview a theme, and that the choice lasts for the visit.
 *
 * ?theme= sets the oc_theme cookie, so the theme's own fetches (which carry no ?theme=)
 * render in the same theme. Only an admin may preview, or any visitor on a demo site.
 *
 * DB-free. Usage:  php tests/theme-preview-sticky.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/base/SharedInstance.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/Validate.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/security/Demo.php';

$root = sys_get_temp_dir() . '/osc-theme-sticky-' . getmypid() . '/';

define('WEB_PATH', 'http://example.test/');
define('CONTENT_PATH', $root);
define('THEMES_PATH', $root . 'themes/');

final class Params
{
    public static array $request = array();

    public static function existParam($name)
    {
        return isset(self::$request[$name]);
    }

    public static function getParamString($name)
    {
        return (string) (self::$request[$name] ?? '');
    }
}

final class Session
{
    public static string $adminId = '';

    public static function getInstance(): self
    {
        return new self();
    }

    public function _get($key)
    {
        return $key === 'adminId' ? self::$adminId : '';
    }
}

final class Cookie
{
    /** @var array<int,array{0:string,1:string,2:int}> */
    public static array $writes = array();

    public static function write(string $name, string $value, int $expires): bool
    {
        self::$writes[] = array($name, $value, $expires);

        return true;
    }
}

function osc_theme()
{
    return 'storefront';
}

function osc_is_api_request()
{
    return !empty($GLOBALS['osc_test_api']);
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hDefines.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/Themes.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/WebThemes.php';

foreach (array('storefront', 'glance') as $slug) {
    mkdir($root . 'themes/' . $slug, 0777, true);
    file_put_contents($root . 'themes/' . $slug . '/index.php', "<?php\n/*\nTheme Name: $slug\nVersion: 1.0.0\n*/\n");
}

$themes = new WebThemes();
$pick   = (new ReflectionMethod(WebThemes::class, 'previewTheme'))->getClosure($themes);

/** One request: its query, cookie and admin; returns the chosen theme. */
$request = static function (array $query, ?string $cookie, string $admin = '') use ($pick): ?string {
    Params::$request = $query;
    Session::$adminId = $admin;
    Cookie::$writes = array();
    $_COOKIE = $cookie === null ? array() : array('oc_theme' => $cookie);

    return $pick();
};

harness_section('Outside demo mode only an admin previews');

pin('a visitor asking for a theme gets the active one', null, $request(array('theme' => 'glance'), null));
pin('a visitor with the cookie gets the active one', null, $request(array(), 'glance'));
pin('an admin gets the asked theme', 'glance', $request(array('theme' => 'glance'), null, '1'));
pin('and the choice is kept in a browser-session cookie', array(array('oc_theme', 'glance', 0)), Cookie::$writes);
pin('the next admin request, with no ?theme=, keeps it', 'glance', $request(array(), 'glance', '1'));
pin('asking again for the kept theme', 'glance', $request(array('theme' => 'glance'), 'glance', '1'));
check('does not write the cookie again', Cookie::$writes === array());

harness_section('Ending and refusing a preview');

pin('?theme= with nothing ends it', null, $request(array('theme' => ''), 'glance', '1'));
check('and deletes the cookie', Cookie::$writes[0][0] === 'oc_theme' && Cookie::$writes[0][2] < time());
pin('?theme= with the active theme ends it', null, $request(array('theme' => 'storefront'), 'glance', '1'));
pin('a theme that is not installed is ignored', null, $request(array('theme' => '../outside'), null, '1'));
check('and writes no cookie', Cookie::$writes === array());
pin('a cookie naming no installed theme is ignored', null, $request(array(), '../outside', '1'));

$GLOBALS['osc_test_api'] = true;
pin('the REST API never previews', null, $request(array('theme' => 'glance'), null, '1'));
check('and writes no cookie on an API answer', Cookie::$writes === array());
$GLOBALS['osc_test_api'] = false;

harness_section('On a demo site any visitor previews');

// DEMO and OC_ADMIN cannot be undefined again, so these sections come last.
define('DEMO', true);
pin('a visitor asking for a theme gets it', 'glance', $request(array('theme' => 'glance'), null));
pin('a visitor keeps it on the next request', 'glance', $request(array(), 'glance'));
pin('and can end it', null, $request(array('theme' => ''), 'glance'));
pin('a theme that is not installed is still ignored', null, $request(array('theme' => '../outside'), null));

harness_section('Admin pages leave the preview alone');

define('OC_ADMIN', true);
pin('an admin page never previews', null, $request(array('theme' => 'glance'), 'glance', '1'));
check('and does not touch the cookie', Cookie::$writes === array());

$rm = static function (string $dir) use (&$rm) {
    foreach (array_diff(scandir($dir) ?: array(), array('.', '..')) as $entry) {
        is_dir("$dir/$entry") ? $rm("$dir/$entry") : @unlink("$dir/$entry");
    }
    @rmdir($dir);
};
$rm(rtrim($root, '/'));

exit(harness_result());
