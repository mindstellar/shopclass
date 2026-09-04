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
 * Pins what a theme declares about itself: its view names, and its widget zones.
 *
 * The view list used to be a private array on WebThemes and is now core's
 * baseline plus whatever the theme declares. Both directions of a wrong list are
 * silent: a name added to it forbids a slug that live sites are already using,
 * and a name dropped from it lets a page shadow the route of the same name --
 * the page simply stops resolving, with no error anywhere.
 *
 * Widget zones have the same shape of failure. A theme that declares nothing
 * must fall back to the `Widgets:` line in its index.php, each slug standing in
 * as its own label -- that is every theme written before the declaration
 * existed, and a fallback that stops answering empties their admin screen and
 * strands every widget the site has placed.
 *
 * DB-free. Usage:  php tests/theme-views.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeSupports.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeViews.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/widgets/WidgetRegistry.php';

$themeRoot = sys_get_temp_dir() . '/osc-theme-views-' . getmypid() . '/';

/** Stands in for WebThemes::newInstance()->getCurrentThemePath(). */
class WebThemes
{
    public static string $path = '';
    /** @var string[] what the theme's `Widgets:` line parses to */
    public static array $headerLocations = array();

    public static function newInstance(): self
    {
        return new self();
    }

    public function getCurrentThemePath(): string
    {
        return self::$path;
    }

    /**
     * @param string $theme
     *
     * @return array
     */
    public function loadThemeInfo($theme)
    {
        return array('locations' => self::$headerLocations);
    }
}

function osc_theme(): string
{
    return 'fixture';
}

/** Minimal filter bus: only `widget_locations` is exercised here. */
$GLOBALS['testFilter'] = null;

function osc_apply_filter($hook, $content, ...$args)
{
    $fn = $GLOBALS['testFilter'];
    if ($hook === 'widget_locations' && is_callable($fn)) {
        return $fn($content, ...$args);
    }

    return $content;
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hWidgets.php';

WebThemes::$path = $themeRoot;
if (!is_dir($themeRoot)) {
    mkdir($themeRoot, 0777, true);
}

$supports = \mindstellar\theme\ThemeSupports::instance();

harness_section('core baseline, verbatim');

// The exact list WebThemes::$pages held before it was retired. Order included:
// this is the set the admin page editor refuses, and it must not drift.
$legacy = array(
    '404',
    'contact',
    'alert-form',
    'custom',
    'footer',
    'functions',
    'head',
    'header',
    'inc.search',
    'index',
    'item-contact',
    'item-edit',
    'item-post',
    'item-send-friend',
    'item',
    'main',
    'page',
    'search',
    'search_gallery',
    'search_list',
    'user-alerts',
    'user-change_email',
    'user-change_password',
    'user-dashboard',
    'user-delete_account',
    'user-forgot_password',
    'user-items',
    'user-login',
    'user-profile',
    'user-recover',
    'user-register',
);

$supports->reset();
pin('a theme that declares nothing gets the legacy list unchanged', $legacy, osc_theme_view_names());
pin('and nothing has been added to it', 31, count($legacy));

harness_section('a declaration adds, never replaces');
$supports->reset();
osc_add_theme_support('views', array('user-wishlist', 'template-promo'));
$names = osc_theme_view_names();
check('the whole baseline survives', array_slice($names, 0, count($legacy)) === $legacy);
check('user-wishlist is reserved', in_array('user-wishlist', $names, true));
check('template-promo is reserved', in_array('template-promo', $names, true));
pin('nothing else was added', count($legacy) + 2, count($names));

harness_section('declared names are normalized');
$supports->reset();
osc_add_theme_support('views', array('user-wishlist.php', 'parts/user-saved.php', '  spaced  '));
$names = osc_theme_view_names();
check('.php is stripped', in_array('user-wishlist', $names, true));
check('a directory is stripped', in_array('user-saved', $names, true));
check('surrounding space is trimmed', in_array('spaced', $names, true));

harness_section('a malformed declaration declares nothing');
foreach (array(true, 'user-wishlist', 42, array(1, 2), array('')) as $i => $args) {
    $supports->reset();
    osc_add_theme_support('views', $args);
    $names = osc_theme_view_names();
    $extra = array_values(array_diff($names, $legacy));
    // A bare string is a one-item list; everything else here adds nothing.
    $want = $args === 'user-wishlist' ? array('user-wishlist') : array();
    pin('shape ' . $i . ' adds only what it should', $want, $extra);
}

harness_section('comparison stays case-sensitive');
$supports->reset();
check('"contact" is reserved', in_array('contact', osc_theme_view_names(), true));
check('"Contact" is not', !in_array('Contact', osc_theme_view_names(), true));

harness_section('widget zones: the `Widgets:` header answers when nothing is declared');
$supports->reset();
WebThemes::$headerLocations = array('header', 'footer');
pin(
    'every slug is its own label, in the order the header lists them',
    array(
        'header' => array('label' => 'header', 'description' => ''),
        'footer' => array('label' => 'footer', 'description' => ''),
    ),
    osc_widget_locations()
);

harness_section('widget zones: a declaration wins over the header');
$supports->reset();
osc_add_theme_support('widget_locations', array(
    'footer' => array('label' => 'Colophon', 'description' => 'Above the copyright line.'),
    'header' => array('label' => 'Masthead'),
));
pin(
    'labels, descriptions and declared order',
    array(
        'footer' => array('label' => 'Colophon', 'description' => 'Above the copyright line.'),
        'header' => array('label' => 'Masthead', 'description' => ''),
    ),
    osc_widget_locations()
);

harness_section('widget zones: a theme with neither has none');
$supports->reset();
WebThemes::$headerLocations = array();
pin('no zones', array(), osc_widget_locations());

harness_section('widget zones: the shapes a declaration arrives in');
WebThemes::$headerLocations = array();
$shapes = array(
    'a bare list'       => array('header', 'footer'),
    'slug => label'     => array('header' => 'Masthead', 'footer' => 'Colophon'),
    'a single spec'     => array('header' => array('label' => 'Masthead')),
);
foreach ($shapes as $label => $args) {
    $supports->reset();
    osc_add_theme_support('widget_locations', $args);
    $out = osc_widget_locations();
    check($label . ' resolves', $out !== array() && isset($out['header']['label'], $out['header']['description']));
}

harness_section('widget zones: a slug that could reach markup is dropped');
$supports->reset();
osc_add_theme_support('widget_locations', array('ok-zone', 'not ok', '<script>', '', str_repeat('x', 61)));
pin(
    'only the well-formed slug survives',
    array('ok-zone' => array('label' => 'ok-zone', 'description' => '')),
    osc_widget_locations()
);

harness_section('widget zones: a plugin adds one through the filter');
$supports->reset();
WebThemes::$headerLocations = array('header');
$GLOBALS['testFilter'] = static function (array $locations): array {
    $locations['sidebar'] = array('label' => 'Sidebar', 'description' => 'From a plugin.');

    return $locations;
};
$out = osc_widget_locations();
check("the theme's own zone survives", isset($out['header']));
pin("the plugin's zone is added", array('label' => 'Sidebar', 'description' => 'From a plugin.'), $out['sidebar'] ?? array());

$GLOBALS['testFilter'] = static fn (): string => 'not-a-map';
pin('a filter returning junk is ignored', array('header' => array('label' => 'header', 'description' => '')), osc_widget_locations());
$GLOBALS['testFilter'] = null;

@unlink($themeRoot . 'user-profile.php');
@rmdir($themeRoot);

exit(harness_result());
