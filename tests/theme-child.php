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
 * Pins what a child theme inherits from its parent.
 *
 * The pieces of this worked before anything was pinned -- the `Parent Theme:` header is
 * parsed, views walk to the parent, chrome walks to the parent -- but nothing described
 * the order or held it still, so it could be reordered by accident and no test would say
 * so. A theme is somebody else's code: a walk that changes silently breaks a site we
 * cannot see.
 *
 * What is pinned here:
 *
 *  - the walk is child, then parent, then storefront, in that order and without repeats;
 *  - a theme naming itself as its own parent resolves once, not forever;
 *  - an A -> B -> A pair is one level deep, so it terminates;
 *  - a `Parent Theme:` naming a directory that is not installed is skipped, and the child
 *    still renders on storefront rather than on nothing;
 *  - a parent name carrying a path is refused before it reaches the filesystem.
 *
 * DB-free. Usage:  php tests/theme-child.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeSupports.php';

$root = sys_get_temp_dir() . '/osc-theme-child-' . getmypid() . '/';

/** Stands in for the real WebThemes: a theme name, its path, and its declared parent. */
class WebThemes
{
    /** @var array<string,string> theme slug => declared parent slug */
    public static array $parents = array();
    public static string $current = 'child';
    public static string $root    = '';

    public static function newInstance(): self
    {
        return new self();
    }

    public function getCurrentTheme(): string
    {
        return self::$current;
    }

    public function getCurrentThemePath(): string
    {
        return self::$root . self::$current . '/';
    }

    /**
     * @param string $theme
     *
     * @return array<string,string>|false
     */
    public function loadThemeInfo($theme)
    {
        if (!is_dir(self::$root . $theme)) {
            return false;
        }

        return array('template' => self::$parents[$theme] ?? '');
    }
}

function osc_themes_path(): string
{
    return WebThemes::$root;
}

function osc_content_path(): string
{
    return WebThemes::$root === '' ? '' : dirname(rtrim(WebThemes::$root, '/')) . '/';
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php';

WebThemes::$root = $root . 'themes/';

/** Create a theme directory, optionally with a file in it. */
$makeTheme = static function (string $slug, string $parent = '', array $files = array()) use ($root) {
    $dir = $root . 'themes/' . $slug . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    WebThemes::$parents[$slug] = $parent;
    foreach ($files as $rel) {
        $sub = dirname($dir . $rel);
        if (!is_dir($sub)) {
            mkdir($sub, 0777, true);
        }
        file_put_contents($dir . $rel, '<?php /* ' . $slug . ' */');
    }
};

/** The walk as theme slugs, so a pin reads as an order rather than as temp paths. */
$walk = static function () use ($root) {
    // The helper caches per active theme; each case below uses its own slug, so the
    // cache never answers for a shape it did not see.
    $names = array();
    foreach (osc_theme_template_paths() as $path) {
        $names[] = basename(rtrim($path, '/'));
    }

    return $names;
};

$makeTheme('storefront');

harness_section('The walk is child, parent, storefront');

$makeTheme('parent-a');
$makeTheme('child-a', 'parent-a');
WebThemes::$current = 'child-a';
pin('a child with an installed parent walks through it', array('child-a', 'parent-a', 'storefront'), $walk());

$makeTheme('plain-a');
WebThemes::$current = 'plain-a';
pin('a theme with no parent still falls back to storefront', array('plain-a', 'storefront'), $walk());

WebThemes::$current = 'storefront';
pin('storefront does not appear twice in its own walk', array('storefront'), $walk());

harness_section('A parent that cannot be walked to');

$makeTheme('child-missing', 'nowhere');
WebThemes::$current = 'child-missing';
pin(
    'a parent that is not installed is skipped, the child still renders',
    array('child-missing', 'storefront'),
    $walk()
);

$makeTheme('child-dots', '../../etc');
WebThemes::$current = 'child-dots';
pin('a parent name carrying a path is refused', array('child-dots', 'storefront'), $walk());

$makeTheme('child-blank', '');
WebThemes::$current = 'child-blank';
pin('an empty Parent Theme is not a parent', array('child-blank', 'storefront'), $walk());

harness_section('A walk cannot loop');

$makeTheme('selfie', 'selfie');
WebThemes::$current = 'selfie';
pin('a theme naming itself resolves once, not forever', array('selfie', 'storefront'), $walk());

$makeTheme('loop-a', 'loop-b');
$makeTheme('loop-b', 'loop-a');
WebThemes::$current = 'loop-a';
pin('an A -> B -> A pair is one level deep, so it terminates', array('loop-a', 'loop-b', 'storefront'), $walk());

harness_section('A file resolves to the first theme in the walk that has it');

$makeTheme('parent-v', '', array('item.php', 'search.php'));
$makeTheme('child-v', 'parent-v', array('item.php'));
WebThemes::$current = 'child-v';

/** Which theme in the walk owns $view -- the resolution every caller of the walk does. */
$owner = static function (string $view) {
    foreach (osc_theme_template_paths() as $path) {
        if (file_exists($path . $view)) {
            return basename(rtrim($path, '/'));
        }
    }

    return null;
};
pin('the child wins for a file it ships', 'child-v', $owner('item.php'));
pin('the parent answers for one it does not', 'parent-v', $owner('search.php'));
pin('a file neither ships resolves to nothing', null, $owner('nothing-here.php'));

harness_section('The child has the last word on theme support');

// The child's functions.php is required first and the parent's second, so a registry
// that simply takes the newest value hands every contested feature to the parent --
// the opposite of what a child theme is for. Declaring nothing must still inherit.
$supports = \mindstellar\theme\ThemeSupports::instance();

$supports->reset();
$supports->add('views', array('from-child'));
$supports->beginInherited();
$supports->add('views', array('from-parent'));
$supports->add('widgets', array('parent-only'));
$supports->endInherited();

pin('a feature both declare belongs to the child', array('from-child'), $supports->get('views'));
pin('a feature only the parent declares is inherited', array('parent-only'), $supports->get('widgets'));

$supports->reset();
$supports->beginInherited();
$supports->add('views', array('from-parent'));
$supports->endInherited();
pin('with no child declaration the parent stands', array('from-parent'), $supports->get('views'));

$supports->reset();
$supports->add('views', array('first'));
$supports->add('views', array('second'));
pin('a theme may still change its own mind', array('second'), $supports->get('views'));
$supports->reset();

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
