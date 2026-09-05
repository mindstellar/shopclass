<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins how core finds a theme's page chrome.
 *
 * Core used to probe for a root header.php + footer.php only. Storefront -- the
 * default theme -- has neither (its chrome is common/header.php + common/footer.php),
 * so the probe failed on a default install and every core-rendered page dropped
 * to the bare standalone shell. Both probes now run, and a theme can declare its
 * own pair outright.
 *
 * A half pair is not chrome: rendering a header with no footer leaves the page
 * unclosed, which is worse than a self-contained one.
 *
 * DB-free. Usage:  php tests/theme-chrome.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeSupports.php';

$themeRoot = sys_get_temp_dir() . '/osc-theme-chrome-' . getmypid() . '/';

/** Stands in for WebThemes::newInstance()->getCurrentThemePath(). */
class WebThemes
{
    public static string $path = '';
    /** Theme slug of the declared parent, or '' for none. */
    public static string $parent = '';

    public static function newInstance(): self
    {
        return new self();
    }

    public function getCurrentThemePath(): string
    {
        return self::$path;
    }

    public function getCurrentTheme(): string
    {
        return 'child';
    }

    /** @return array<string,string> */
    public function loadThemeInfo($theme): array
    {
        return array('template' => self::$parent);
    }
}

/** Where a parent theme's directory would live. */
function osc_themes_path(): string
{
    return sys_get_temp_dir() . '/osc-theme-chrome-' . getmypid() . '-themes/';
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php';

WebThemes::$path = $themeRoot;

/**
 * Rebuild the fake theme with exactly $files present.
 *
 * @param string[] $files
 */
$makeTheme = static function (array $files) use ($themeRoot) {
    foreach (array('header.php', 'footer.php', 'common/header.php', 'common/footer.php', 'chrome/top.php',
                   'chrome/bottom.php') as $rel) {
        if (file_exists($themeRoot . $rel)) {
            unlink($themeRoot . $rel);
        }
    }
    foreach ($files as $rel) {
        $dir = dirname($themeRoot . $rel);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($themeRoot . $rel, '<?php /* fixture */');
    }
};

$rel = static function (?array $chrome) use ($themeRoot) {
    if ($chrome === null) {
        return null;
    }

    return array(
        'header' => str_replace($themeRoot, '', $chrome['header']),
        'footer' => str_replace($themeRoot, '', $chrome['footer']),
    );
};

harness_section('no chrome at all');
$makeTheme(array());
\mindstellar\theme\ThemeSupports::instance()->reset();
check('a theme with neither pair has no chrome', osc_theme_chrome() === null);
check('osc_theme_has_chrome() agrees', osc_theme_has_chrome() === false);

harness_section('the root pair (bender, shopclass)');
$makeTheme(array('header.php', 'footer.php'));
pin(
    'root header.php + footer.php',
    array('header' => 'header.php', 'footer' => 'footer.php'),
    $rel(osc_theme_chrome())
);

harness_section('the common/ pair (storefront)');
$makeTheme(array('common/header.php', 'common/footer.php'));
pin(
    'common/header.php + common/footer.php',
    array('header' => 'common/header.php', 'footer' => 'common/footer.php'),
    $rel(osc_theme_chrome())
);

harness_section('a half pair is not chrome');
$makeTheme(array('header.php'));
check('root header with no footer', osc_theme_chrome() === null);
$makeTheme(array('common/header.php'));
check('common/ header with no footer', osc_theme_chrome() === null);
$makeTheme(array('footer.php'));
check('root footer with no header', osc_theme_chrome() === null);

harness_section('the root pair wins over common/');
$makeTheme(array('header.php', 'footer.php', 'common/header.php', 'common/footer.php'));
pin(
    'both present, root chosen',
    array('header' => 'header.php', 'footer' => 'footer.php'),
    $rel(osc_theme_chrome())
);

harness_section('a declaration wins over both probes');
$makeTheme(array('header.php', 'footer.php', 'chrome/top.php', 'chrome/bottom.php'));
osc_add_theme_support('chrome', array('header' => 'chrome/top.php', 'footer' => 'chrome/bottom.php'));
pin(
    'declared pair chosen over the root pair',
    array('header' => 'chrome/top.php', 'footer' => 'chrome/bottom.php'),
    $rel(osc_theme_chrome())
);
check('osc_theme_supports returns the declaration', is_array(osc_theme_supports('chrome')));

harness_section('a declaration that does not resolve falls back to the probes');
osc_add_theme_support('chrome', array('header' => 'nope/top.php', 'footer' => 'nope/bottom.php'));
pin(
    'missing declared files fall through to the root pair',
    array('header' => 'header.php', 'footer' => 'footer.php'),
    $rel(osc_theme_chrome())
);

harness_section('a declaration cannot escape the theme directory');
osc_add_theme_support('chrome', array('header' => '../../../etc/passwd', 'footer' => '../../../etc/passwd'));
pin(
    'a traversing declaration is refused, probes still answer',
    array('header' => 'header.php', 'footer' => 'footer.php'),
    $rel(osc_theme_chrome())
);
osc_add_theme_support('chrome', array('header' => '/etc/passwd', 'footer' => '/etc/passwd'));
pin(
    'an absolute declaration is refused',
    array('header' => 'header.php', 'footer' => 'footer.php'),
    $rel(osc_theme_chrome())
);

harness_section('removing support goes back to the probes');
osc_remove_theme_support('chrome');
check('nothing declared', osc_theme_supports('chrome') === false);
pin(
    'the root pair answers again',
    array('header' => 'header.php', 'footer' => 'footer.php'),
    $rel(osc_theme_chrome())
);

harness_section('a child theme inherits its parent\'s chrome');
// A theme with a declared parent is one identity: a child that ships no chrome of
// its own must get the parent's, or every page it does not override renders with
// no header at all. The bundled fallback theme is deliberately not in this walk.
$parentRoot = osc_themes_path() . 'folio-parent/';
if (!is_dir($parentRoot . 'common')) {
    mkdir($parentRoot . 'common', 0777, true);
}
file_put_contents($parentRoot . 'common/header.php', '<?php /* parent */');
file_put_contents($parentRoot . 'common/footer.php', '<?php /* parent */');

$makeTheme(array());
\mindstellar\theme\ThemeSupports::instance()->reset();
WebThemes::$parent = '';
check('no parent declared: still no chrome', osc_theme_chrome() === null);

WebThemes::$parent = 'folio-parent';
$fromParent = osc_theme_chrome();
check('the child falls back to the parent', $fromParent !== null);
check(
    'and it is the parent\'s file, not the child\'s',
    $fromParent !== null && strpos($fromParent['header'], 'folio-parent/common/header.php') !== false
);

// The child's own chrome must still win when it has some.
$makeTheme(array('header.php', 'footer.php'));
pin(
    'a child that ships chrome keeps its own',
    array('header' => 'header.php', 'footer' => 'footer.php'),
    $rel(osc_theme_chrome())
);

// A declaration inherited from the parent resolves against the parent too.
$makeTheme(array());
osc_add_theme_support('chrome', array('header' => 'common/header.php', 'footer' => 'common/footer.php'));
check(
    'a parent-declared pair resolves in the parent directory',
    ($c = osc_theme_chrome()) !== null && strpos($c['header'], 'folio-parent/') !== false
);
WebThemes::$parent = '';

@unlink($parentRoot . 'common/header.php');
@unlink($parentRoot . 'common/footer.php');
@rmdir($parentRoot . 'common');
@rmdir($parentRoot);
@rmdir(osc_themes_path());

$makeTheme(array());
@rmdir($themeRoot . 'common');
@rmdir($themeRoot . 'chrome');
@rmdir($themeRoot);

exit(harness_result());
