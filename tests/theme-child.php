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

// The real path helpers read these, so the fixture tree becomes the whole world and the
// functions under test are the shipped ones rather than local copies.
define('WEB_PATH', 'http://example.test/');
define('CONTENT_PATH', $root);
define('THEMES_PATH', $root . 'themes/');

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

    public function getCurrentThemeUrl(): string
    {
        return WEB_PATH . 'themes/' . self::$current . '/';
    }

    /**
     * The real method, side effect and all: it does not describe the parent, it becomes
     * it for the rest of the request. Reproduced here so a pin can show what that cost.
     */
    public function setParentTheme(): void
    {
        self::$current = self::$parents[self::$current] ?? self::$current;
    }

    /** The bundled last resort. This one IS a switch: the site left its own theme. */
    public function setGuiTheme(): void
    {
        self::$current = 'storefront';
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

// osc_base_url() runs its result through a filter; the walk does not depend on plugins,
// so the registry stands in as a pass-through rather than being loaded.
if (!function_exists('osc_apply_filter')) {
    function osc_apply_filter($hook, $value = '')
    {
        return $value;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php';
// hDefines brings osc_theme_asset_url() and the two helpers that route through it, plus
// its own osc_base_url()/osc_base_path(); WebThemes::$root is set under that base below.
require_once ABS_PATH . 'oc-includes/osclass/helpers/hDefines.php';

WebThemes::$root = THEMES_PATH;

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
        // Echoes its own slug, so a pin can say which theme's copy was rendered.
        file_put_contents($dir . $rel, '<?php echo ' . var_export($slug, true) . ';');
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

// No slash, so it passed the name check, and it named the folder above the themes.
$makeTheme('child-dotdot', '..');
WebThemes::$current = 'child-dotdot';
pin('a parent named .. is refused', array('child-dotdot', 'storefront'), $walk());

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

harness_section('An asset falls back without moving the theme');

$makeTheme('parent-s', '', array('css/style.css', 'js/app.js'));
$makeTheme('child-s', 'parent-s', array('css/child.css'));
WebThemes::$current = 'child-s';

/** The theme directory an asset URL points at, through the long-standing public helper. */
$asset = static function (string $file) {
    $url   = osc_current_web_theme_url($file);
    $parts = explode('/themes/', $url, 2);

    return count($parts) === 2 ? $parts[1] : $url;
};

pin('a file the child ships comes from the child', 'child-s/css/child.css', $asset('css/child.css'));
pin('a file only the parent ships comes from the parent', 'parent-s/css/style.css', $asset('css/style.css'));

// Resolving used to run through setParentTheme(), which switches the active theme for the
// rest of the request -- so one parent-only asset sent every later lookup to the parent,
// and the child's own stylesheet came back as a 404 under the parent's directory.
pin('resolving a parent asset leaves the active theme alone', 'child-s', WebThemes::$current);
pin('the child is still the child afterwards', 'child-s/css/child.css', $asset('css/child.css'));

pin('a file neither ships stays on the child, so the 404 names the right theme',
    'child-s/css/nothing.css', $asset('css/nothing.css'));
pin('the styles helper walks too', 'parent-s/css/style.css', osc_current_web_theme_styles_url('style.css')
    ? explode('/themes/', osc_current_web_theme_styles_url('style.css'), 2)[1] : '');
pin('the js helper walks too', 'parent-s/js/app.js',
    explode('/themes/', osc_current_web_theme_js_url('app.js'), 2)[1]);

harness_section('Loading a parent view does not change the active theme');

$makeTheme('parent-w', '', array('footer.php'));
$makeTheme('child-w', 'parent-w', array('header.php'));
WebThemes::$current = 'child-w';

ob_start();
osc_current_web_theme_path('header.php');
$own = trim(ob_get_clean());
pin('the child renders its own view', 'child-w', $own);
pin('and is still the active theme', 'child-w', WebThemes::$current);

ob_start();
osc_current_web_theme_path('footer.php');
$inherited = trim(ob_get_clean());
pin('a view only the parent has renders from the parent', 'parent-w', $inherited);
// setParentTheme() does not describe the parent, it becomes it -- so this used to leave
// every later lookup, including the child's own assets, resolving under the parent.
pin('and the child is STILL the active theme', 'child-w', WebThemes::$current);

pin(
    'the child asset still points at the child afterwards',
    'child-w/css/x.css',
    explode('/themes/', osc_current_web_theme_url('css/x.css'), 2)[1]
);

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
