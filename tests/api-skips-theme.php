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
 * An API request does not load the active theme's functions.php, and a page request still does.
 * A plugin can switch the theme back on for the API with the `api_theme_functions_enabled` filter.
 * Also: osc_is_api_request() reads both URL forms.
 *
 * DB-free. Usage:  php tests/api-skips-theme.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/api-boot.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/Validate.php';

$root = sys_get_temp_dir() . '/osc-api-theme-' . getmypid() . '/';
define('WEB_PATH', 'http://example.test/');
define('CONTENT_PATH', $root);
define('THEMES_PATH', $root . 'themes/');
define('REL_WEB_URL', '/');

foreach (['probe', 'probe-two', 'probe-three'] as $slug) {
    $dir = $root . 'themes/' . $slug . '/';
    mkdir($dir, 0777, true);
    file_put_contents($dir . 'index.php', "<?php\n/*\nTheme Name: Probe\nVersion: 1.0.0\n*/\n");
    file_put_contents($dir . 'functions.php', "<?php\n\$GLOBALS['probe_functions_loaded'] = (\$GLOBALS['probe_functions_loaded'] ?? 0) + 1;\n");
}
$GLOBALS['stub_theme'] = 'probe';

$GLOBALS['stub'] = ['page' => '', 'uri' => '/', 'rewrite' => '1', 'filter' => false];

class Session
{
    public static function getInstance()
    {
        return new self();
    }

    public static function newInstance()
    {
        return self::getInstance();
    }

    public function _get($key)
    {
        return '';
    }
}

class Params
{
    public static function getParamString($name)
    {
        return $name === 'page' ? $GLOBALS['stub']['page'] : '';
    }

    public static function getRequestURI($a = false, $b = true, $c = true)
    {
        return $GLOBALS['stub']['uri'];
    }
}

class Preference
{
    public static function getInstance()
    {
        return new self();
    }

    public static function newInstance()
    {
        return self::getInstance();
    }

    public function get($key)
    {
        return $GLOBALS['stub']['rewrite'];
    }
}

function osc_theme()
{
    return $GLOBALS['stub_theme'];
}

function osc_themes_path()
{
    return THEMES_PATH;
}

function osc_base_url()
{
    return WEB_PATH;
}

function osc_base_path()
{
    return CONTENT_PATH;
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/Themes.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/WebThemes.php';

$set = static function (string $page, string $uri, string $rewrite = '1'): void {
    $GLOBALS['stub'] = ['page' => $page, 'uri' => $uri, 'rewrite' => $rewrite];
};

harness_section('Detecting an API request');
foreach ([
    '?page=api'                       => ['api', '/index.php?page=api', '0', true],
    '/api/v1/listings'                => ['', '/api/v1/listings', '1', true],
    '/api'                            => ['', '/api', '1', true],
    '/api/ with a query'              => ['', '/api/v1/listings?x=1', '1', true],
    '/api/ with friendly URLs off'    => ['', '/api/v1/listings', '0', false],
    'a page called apple'             => ['', '/apple', '1', false],
    'the home page'                   => ['', '/', '1', false],
    'a search page'                   => ['search', '/search', '1', false],
] as $label => [$page, $uri, $rewrite, $expected]) {
    $set($page, $uri, $rewrite);
    pin($label, $expected, osc_is_api_request());
}

harness_section('The theme\'s functions.php');
$GLOBALS['probe_functions_loaded'] = 0;
$set('', '/search');
WebThemes::init();
pin('a page request loads it', 1, $GLOBALS['probe_functions_loaded']);

$GLOBALS['probe_functions_loaded'] = 0;
$GLOBALS['stub_theme'] = 'probe-two';
$set('api', '/index.php?page=api');
$themes = WebThemes::getInstance();
$load   = new ReflectionMethod('WebThemes', 'loadActive');
$load->setAccessible(true);
$load->invoke($themes);
pin('an API request does not', 0, $GLOBALS['probe_functions_loaded']);
pin('...but the theme is still selected', 'probe-two', $themes->getCurrentTheme());

$GLOBALS['stub_theme'] = 'probe-three';
$set('api', '/index.php?page=api');
api_with_filter('api_theme_functions_enabled', static fn () => true, static fn () => $load->invoke($themes));
pin('a plugin can ask for it with api_theme_functions_enabled', 1, $GLOBALS['probe_functions_loaded']);

exec('rm -rf ' . escapeshellarg($root));
exit(harness_result());
