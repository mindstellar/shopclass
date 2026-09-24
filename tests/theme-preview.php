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
 * Pins that a theme name can only ever name a theme.
 *
 * `?theme=` previews a theme for a signed-in admin, and the value went straight to
 * setCurrentTheme() with nothing checking it. That name becomes a directory, and
 * loadActive() requires functions.php out of it -- so `?theme=../../../tmp/evil`
 * executed /tmp/evil/functions.php. An admin session was the only thing in the way,
 * which is not enough: a demo admin must not be able to run code, and any place the
 * product accepts an upload turns a path into remote execution.
 *
 * Two layers are pinned, because either alone would be a single point of failure:
 *
 *  - the preview name must be one of the installed themes, checked where the request
 *    parameter is read;
 *  - and setCurrentThemePath() refuses to resolve anywhere but inside the themes
 *    directory, whatever a caller hands it.
 *
 * DB-free. Usage:  php tests/theme-preview.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/Validate.php';

$root = sys_get_temp_dir() . '/osc-theme-preview-' . getmypid() . '/';

define('WEB_PATH', 'http://example.test/');
define('CONTENT_PATH', $root);
define('THEMES_PATH', $root . 'themes/');

if (!function_exists('osc_apply_filter')) {
    function osc_apply_filter($hook, $value = '')
    {
        return $value;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hDefines.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/themes/abstract/Themes.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/themes/WebThemes.php';

/** A theme directory, valid enough for getListThemes() to count it. */
$makeTheme = static function (string $slug) use ($root) {
    $dir = $root . 'themes/' . $slug . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents(
        $dir . 'index.php',
        "<?php\n/*\nTheme Name: " . ucfirst($slug) . "\nVersion: 1.0.0\n*/\n"
    );
};

/** Somewhere outside the themes tree holding a functions.php, as an attacker would. */
$outside = $root . 'outside/';
mkdir($outside, 0777, true);
file_put_contents($outside . 'functions.php', "<?php\n\$GLOBALS['osc_preview_executed'] = true;\n");
file_put_contents($outside . 'index.php', "<?php\n/*\nTheme Name: Evil\n*/\n");

$makeTheme('storefront');
$makeTheme('folio');
$makeTheme('my-theme');

$themes = new WebThemes();

/** Where a given theme name resolves to, relative to the fixture root. */
$resolve = static function (string $name) use ($themes, $root) {
    $themes->setCurrentTheme($name);

    return str_replace($root, '', (string) $themes->getCurrentThemePath());
};

harness_section('An installed theme resolves to itself');

pin('a plain name', 'themes/folio/', $resolve('folio'));
pin('a name with a hyphen, which themes really ship', 'themes/my-theme/', $resolve('my-theme'));

harness_section('Anything else resolves to the bundled theme, never outside');

// The fallback is storefront: the request still renders, on a theme that exists.
foreach (array(
    'a parent-directory hop'        => '../outside',
    'several hops'                  => '../../../outside',
    'an absolute path'              => '/etc',
    'a nested path'                 => 'folio/../../outside',
    'a bare dot-dot'                => '..',
    'a name that is not installed'  => 'no-such-theme',
    'an empty name'                 => '',
) as $why => $name) {
    pin($why . ' does not escape', 'themes/storefront/', $resolve($name));
}

harness_section('The escape never reaches a file');

$themes->setCurrentTheme('../outside');
$GLOBALS['osc_preview_executed'] = false;
$path = (string) $themes->getCurrentThemePath();
if (file_exists($path . 'functions.php')) {
    require $path . 'functions.php';
}
check('no functions.php outside the themes tree was required', $GLOBALS['osc_preview_executed'] === false);

harness_section('getListThemes is the allowlist the preview checks against');

$installed = $themes->getListThemes();
sort($installed);
pin('it lists the installed themes', array('folio', 'my-theme', 'storefront'), $installed);
check('and not the directory planted outside', !in_array('outside', $installed, true));
check('a traversal string is not in it', !in_array('../outside', $installed, true));

/* Clean up the fixture tree. */
$rm = static function (string $dir) use (&$rm) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir) ?: array(), array('.', '..')) as $entry) {
        $path = $dir . '/' . $entry;
        is_dir($path) ? $rm($path) : @unlink($path);
    }
    @rmdir($dir);
};
$rm(rtrim($root, '/'));

exit(harness_result());
