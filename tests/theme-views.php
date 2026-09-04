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
 * Pins the view-name vocabulary that guards static-page slugs.
 *
 * The list used to be a private array on WebThemes and is now core's baseline
 * plus whatever the theme declares. Both directions of a wrong list are silent:
 * a name added to it forbids a slug that live sites are already using, and a
 * name dropped from it lets a page shadow the route of the same name -- the page
 * simply stops resolving, with no error anywhere.
 *
 * DB-free. Usage:  php tests/theme-views.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeSupports.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeViews.php';

$themeRoot = sys_get_temp_dir() . '/osc-theme-views-' . getmypid() . '/';

/** Stands in for WebThemes::newInstance()->getCurrentThemePath(). */
class WebThemes
{
    public static string $path = '';

    public static function newInstance(): self
    {
        return new self();
    }

    public function getCurrentThemePath(): string
    {
        return self::$path;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php';

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

harness_section('osc_theme_provides(): files on disk');
$supports->reset();
file_put_contents($themeRoot . 'user-profile.php', '<?php /* fixture */');
check('a view the theme ships', osc_theme_provides('user-profile.php') === true);
check('a view it does not', osc_theme_provides('user-billing-wallet.php') === false);
check('an empty name', osc_theme_provides('') === false);
check('a traversing name is refused', osc_theme_provides('../../../etc/passwd') === false);

harness_section('osc_theme_provides(): declared without a file');
$supports->reset();
osc_add_theme_support('views', array('user-wishlist'));
check('declared with no file on disk', osc_theme_provides('user-wishlist.php') === true);
check('and without the extension', osc_theme_provides('user-wishlist') === true);
check('an undeclared name is still false', osc_theme_provides('user-billing-buy.php') === false);

harness_section('removing the declaration goes back to the baseline');
osc_remove_theme_support('views');
pin('baseline again', $legacy, osc_theme_view_names());
check('the declared-only view is gone', osc_theme_provides('user-wishlist.php') === false);

@unlink($themeRoot . 'user-profile.php');
@rmdir($themeRoot);

exit(harness_result());
