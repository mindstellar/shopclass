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
 * Pins osc_locate_template(): which view a controller ends up rendering.
 *
 * Every front-end controller used to name exactly one view. It now names an
 * ordered list, and the two orderings in play -- candidate order and theme order
 * -- have to resolve in that priority or a page silently changes theme: if
 * candidate order won outright, a fallback theme's specific view would beat the
 * active theme's generic one, and the visitor would get a page from a theme the
 * site is not running.
 *
 * The other silent direction is the miss. A list where nothing exists must come
 * back as the last candidate, which is exactly the name the controller passed
 * before there was a list at all.
 *
 * DB-free. Usage:  php tests/template-hierarchy.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeSupports.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeViews.php';

$content = sys_get_temp_dir() . '/osc-template-hierarchy-' . getmypid() . '/';

/** Stands in for the WebThemes singleton: active theme name, path, parent info. */
class WebThemes
{
    public static string $root  = '';
    public static string $theme = '';
    /** @var array<string,string> theme name => parent theme name */
    public static array $parents = array();

    public static function newInstance(): self
    {
        return new self();
    }

    public function getCurrentTheme(): string
    {
        return self::$theme;
    }

    public function getCurrentThemePath(): string
    {
        return self::$root . 'themes/' . self::$theme . '/';
    }

    /**
     * @param string $theme
     *
     * @return array
     */
    public function loadThemeInfo($theme)
    {
        return array('template' => self::$parents[$theme] ?? '');
    }
}

function osc_themes_path(): string
{
    return WebThemes::$root . 'themes/';
}

function osc_content_path(): string
{
    return WebThemes::$root;
}

/** Minimal filter bus: only `template_candidates` is exercised here. */
$GLOBALS['testFilter'] = null;

function osc_apply_filter($hook, $content, ...$args)
{
    $fn = $GLOBALS['testFilter'];
    if ($hook === 'template_candidates' && is_callable($fn)) {
        return $fn($content, ...$args);
    }

    return $content;
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php';

WebThemes::$root = $content;

/**
 * Create a theme directory holding exactly $views.
 *
 * @param string[] $views
 */
$makeTheme = static function (string $theme, array $views) use ($content) {
    $dir = $content . 'themes/' . $theme . '/';
    if (is_dir($dir)) {
        foreach ((array) glob($dir . '*.php') as $f) {
            unlink($f);
        }
    } else {
        mkdir($dir, 0777, true);
    }
    foreach ($views as $view) {
        file_put_contents($dir . $view, '<?php /* fixture */');
    }
};

harness_section('the stack: active theme, its parent, core\'s fallback theme');
$makeTheme('storefront', array());
$makeTheme('parenttheme', array());
$makeTheme('childtheme', array());
WebThemes::$parents = array('childtheme' => 'parenttheme');
WebThemes::$theme   = 'childtheme';
$stack              = array_map(
    static fn (string $p): string => trim(str_replace($content . 'themes/', '', $p), '/'),
    osc_theme_template_paths()
);
pin('active, then parent, then fallback', array('childtheme', 'parenttheme', 'storefront'), $stack);

harness_section('one candidate behaves exactly as naming one view did');
$makeTheme('childtheme', array('user-profile.php'));
pin('a view the theme ships', 'user-profile.php', osc_locate_template(array('user-profile.php'), 'user-profile'));
pin(
    'a view no theme ships comes back anyway',
    'user-billing-wallet.php',
    osc_locate_template(array('user-billing-wallet.php'), 'billing')
);

harness_section('candidate order inside the active theme');
$makeTheme('childtheme', array('item.php', 'item-12.php'));
pin('the specific view wins', 'item-12.php', osc_locate_template(array('item-12.php', 'item.php'), 'item'));
$makeTheme('childtheme', array('item.php'));
pin('and falls back to the generic one', 'item.php', osc_locate_template(array('item-12.php', 'item.php'), 'item'));

harness_section('theme order beats candidate order');
// The regression this exists for: the active theme has only the generic view and
// a fallback theme happens to ship the specific one. Resolving candidate-first
// would hand the page to a theme the site is not running.
$makeTheme('childtheme', array('item.php'));
$makeTheme('parenttheme', array('item-12.php'));
pin(
    "the active theme's generic view beats the parent's specific one",
    'item.php',
    osc_locate_template(array('item-12.php', 'item.php'), 'item')
);
$makeTheme('childtheme', array());
$makeTheme('storefront', array('item-12.php', 'item.php'));
pin(
    "within the parent, candidate order applies again",
    'item-12.php',
    osc_locate_template(array('item-12.php', 'item.php'), 'item')
);

harness_section('the parent theme is searched before the fallback theme');
$makeTheme('childtheme', array());
$makeTheme('parenttheme', array('search.php'));
$makeTheme('storefront', array('search-jobs.php', 'search.php'));
pin(
    "the parent's generic view beats the fallback theme's specific one",
    'search.php',
    osc_locate_template(array('search-jobs.php', 'search.php'), 'search')
);

harness_section('a theme with no parent');
$makeTheme('lonelytheme', array('main.php'));
$makeTheme('storefront', array('main.php'));
WebThemes::$theme = 'lonelytheme';
$stack            = array_map(
    static fn (string $p): string => trim(str_replace($content . 'themes/', '', $p), '/'),
    osc_theme_template_paths()
);
pin('just the active theme and the fallback', array('lonelytheme', 'storefront'), $stack);
pin('and it answers for itself', 'main.php', osc_locate_template(array('main.php'), 'home'));

harness_section('a candidate cannot escape the theme directory');
$makeTheme('escapetheme', array('main.php'));
WebThemes::$theme = 'escapetheme';
pin(
    'a traversing candidate is dropped',
    'main.php',
    osc_locate_template(array('../../../etc/passwd', 'main.php'), 'home')
);
pin(
    'an absolute candidate is dropped',
    'main.php',
    osc_locate_template(array('/etc/passwd', 'main.php'), 'home')
);
pin('an empty list locates nothing', '', osc_locate_template(array(), 'home'));
pin('a list of nothing but junk locates nothing', '', osc_locate_template(array('', '   ', 42), 'home'));
pin('a bare string is one candidate', 'main.php', osc_locate_template('main.php', 'home'));

harness_section('the template_candidates filter');
$makeTheme('filtertheme', array('main.php', 'main-custom.php'));
WebThemes::$theme = 'filtertheme';

$GLOBALS['testFilter'] = static function (array $candidates, string $context): array {
    if ($context === 'home') {
        array_unshift($candidates, 'main-custom.php');
    }

    return $candidates;
};
pin('a theme adds a view without a core patch', 'main-custom.php', osc_locate_template(array('main.php'), 'home'));
pin('and only for the context it asked for', 'main.php', osc_locate_template(array('main.php'), 'search'));

$GLOBALS['testFilter'] = static fn (): string => 'not-a-list';
pin('a filter returning junk is ignored', 'main.php', osc_locate_template(array('main.php'), 'home'));
$GLOBALS['testFilter'] = null;

// Clean up.
foreach ((array) glob($content . 'themes/*') as $dir) {
    foreach ((array) glob($dir . '/*.php') as $f) {
        unlink($f);
    }
    @rmdir($dir);
}
@rmdir($content . 'themes');
@rmdir($content);

harness_section('editing falls back to the publishing form');
// The two forms are the same fields; core offers item-post.php as the second
// candidate so a theme need not ship a second copy, or a file that includes the
// first. A theme that does ship item-edit.php still wins -- bender does.
$controller = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php');
check(
    'item-edit offers item-post as its fallback',
    (bool) preg_match(
        "/osc_locate_template\(\s*array\('item-edit\.php',\s*'item-post\.php'\)/s",
        $controller
    )
);
check(
    'and item-edit is still the first candidate',
    strpos($controller, "array('item-edit.php', 'item-post.php')") !== false
);

exit(harness_result());
