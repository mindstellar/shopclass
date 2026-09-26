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
 * Pins what core prints into <head> and onto <body>.
 *
 * osc_head() ends by running the `header` hook, which is where every enqueued
 * stylesheet and script lives, along with the robots meta. Both directions of
 * getting that wrong are silent in a way a page still renders through: running
 * it twice ships every asset twice, and dropping it ships an unstyled page with
 * no robots meta and no plugin output at all.
 *
 * The parts are opt-out, and a theme that declares nothing gets all of them --
 * so a theme adopting osc_head() without a declaration must still get a title.
 *
 * Body classes reach a class attribute, and one of them is built from a static
 * page's internal name, which an admin types.
 *
 * DB-free. Usage:  php tests/theme-head.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeSupports.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeViews.php';

/** The request the stubs describe: which page, who is looking at it. */
$GLOBALS['page']       = 'home';
$GLOBALS['loggedIn']   = false;
$GLOBALS['locale']     = 'en_US';
$GLOBALS['direction']  = 'ltr';
$GLOBALS['pageSlug']   = '';
$GLOBALS['hookRuns']   = 0;
$GLOBALS['bodyFilter'] = null;

/** Stands in for the WebThemes singleton; nothing here reads a theme directory. */
class WebThemes
{
    public static function newInstance(): self
    {
        return new self();
    }

    public function getCurrentThemePath(): string
    {
        return sys_get_temp_dir() . '/osc-theme-head-none/';
    }

    public function getCurrentTheme(): string
    {
        return 'fixture';
    }

    /**
     * @param string $theme
     *
     * @return array
     */
    public function loadThemeInfo($theme)
    {
        return array('template' => '', 'locations' => array());
    }
}

function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function osc_apply_filter($hook, $content, ...$args)
{
    if ($hook === 'body_class' && is_callable($GLOBALS['bodyFilter'])) {
        return ($GLOBALS['bodyFilter'])($content, ...$args);
    }

    return $content;
}

function osc_run_hook($hook)
{
    if ($hook === 'header') {
        $GLOBALS['hookRuns']++;
        echo '<!--header-hook-->' . PHP_EOL;
    }
}

function osc_current_user_locale()
{
    return $GLOBALS['locale'];
}

function osc_locale_text_direction()
{
    return $GLOBALS['direction'];
}

function osc_theme()
{
    return 'fixture';
}

function osc_is_web_user_logged_in()
{
    return $GLOBALS['loggedIn'];
}

function osc_static_page_field($field, $locale = '')
{
    return $field === 's_internal_name' ? $GLOBALS['pageSlug'] : '';
}

function meta_title()
{
    return 'A listing "quoted" & sold';
}

function meta_description()
{
    return $GLOBALS['page'] === 'item' ? 'A description.' : '';
}

function meta_keywords()
{
    return $GLOBALS['page'] === 'search' ? 'cars, bikes' : '';
}

function osc_get_canonical()
{
    return 'https://example.test/here';
}

function osc_page_title()
{
    return 'Example Site';
}

function osc_search_url($params = array())
{
    return 'https://example.test/search/sFeed,rss';
}

function osc_update_search_url($params = array())
{
    // Echoes the page it was asked for, so a pin can tell rel=prev from rel=next.
    if (isset($params['iPage']) && $params['iPage'] !== '') {
        return 'https://example.test/search/cars/p' . (int)$params['iPage'];
    }
    if (isset($params['iPage'])) {
        return 'https://example.test/search/cars';
    }

    return 'https://example.test/search/cars/sFeed,rss';
}

function osc_search_page()
{
    return $GLOBALS['searchPage'] ?? 0;
}

function osc_search_total_pages()
{
    return $GLOBALS['searchTotal'] ?? 0;
}

// Every page predicate answers off one global, so a test names a page once.
foreach (array(
    'osc_is_home_page'            => 'home',
    'osc_is_search_page'          => 'search',
    'osc_is_search_category_page' => 'search-category',
    'osc_is_ad_page'              => 'item',
    'osc_is_publish_page'         => 'item-post',
    'osc_is_edit_page'            => 'item-edit',
    'osc_is_item_contact_page'    => 'item-contact',
    'osc_is_contact_page'         => 'contact',
    'osc_is_custom_page'          => 'custom',
    'osc_is_login_page'           => 'login',
    'osc_is_register_page'        => 'register',
    'osc_is_recover_page'         => 'recover',
    'osc_is_forgot_page'          => 'forgot',
    'osc_is_public_profile'       => 'public-profile',
    'osc_is_404'                  => '404',
    'osc_is_user_dashboard'       => 'dashboard',
    'osc_is_user_profile'         => 'profile',
    'osc_is_list_items'           => 'items',
    'osc_is_list_alerts'          => 'alerts',
    'osc_is_change_email_page'    => 'change-email',
    'osc_is_change_password_page' => 'change-password',
    'osc_is_change_username_page' => 'change-username',
    'osc_is_static_page'          => 'static-page',
) as $fn => $page) {
    eval('function ' . $fn . '() { return $GLOBALS["page"] === ' . var_export($page, true) . '; }');
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php';

$supports = \mindstellar\theme\ThemeSupports::instance();

/** Capture what osc_head() prints. */
$head = static function (): string {
    $GLOBALS['hookRuns'] = 0;
    ob_start();
    osc_head();

    return (string) ob_get_clean();
};

harness_section('osc_head(): a theme that declares nothing gets everything');
$supports->reset();
$GLOBALS['page'] = 'item';
$out             = $head();
check('charset', strpos($out, '<meta charset="utf-8">') !== false);
check('viewport', strpos($out, 'name="viewport"') !== false);
check('title', strpos($out, '<title>') !== false);
check('description', strpos($out, 'name="description" content="A description."') !== false);
check('canonical', strpos($out, 'rel="canonical" href="https://example.test/here"') !== false);
check('feed', strpos($out, 'application/rss+xml') !== false);
pin('the header hook ran exactly once', 1, $GLOBALS['hookRuns']);

harness_section('osc_head(): the title is escaped');
// meta_title() returns whatever an item was named, unescaped.
check(
    'quotes and ampersands do not reach the markup raw',
    strpos($head(), '<title>A listing &quot;quoted&quot; &amp; sold</title>') !== false
);

harness_section('osc_head(): an empty part prints nothing rather than an empty tag');
$GLOBALS['page'] = 'home';
$out             = $head();
check('no empty description', strpos($out, 'name="description"') === false);
check('no empty keywords', strpos($out, 'name="keywords"') === false);

harness_section('osc_head(): parts are opt-out, one at a time');
$GLOBALS['page'] = 'item';
foreach (array('charset', 'viewport', 'title', 'description', 'canonical', 'feed') as $part) {
    $supports->reset();
    osc_add_theme_support('head', array($part => false));
    $out    = $head();
    $marker = array(
        'charset'     => '<meta charset',
        'viewport'    => 'name="viewport"',
        'title'       => '<title>',
        'description' => 'name="description"',
        'canonical'   => 'rel="canonical"',
        'feed'        => 'application/rss+xml',
    );
    check('"' . $part . '" can be declined', strpos($out, $marker[$part]) === false);
    pin('and the header hook still runs once without ' . $part, 1, $GLOBALS['hookRuns']);
}

harness_section('osc_head(): a bare declaration still means everything');
$supports->reset();
osc_add_theme_support('head');
check('title survives a bare true', strpos($head(), '<title>') !== false);

harness_section('osc_head(): the search feed points at the current search');
$supports->reset();
$GLOBALS['page'] = 'search';
check('results page feeds the search', strpos($head(), 'search/cars/sFeed,rss') !== false);
$GLOBALS['page'] = 'item';
check('everywhere else feeds the site', strpos($head(), 'example.test/search/sFeed,rss') !== false);

harness_section('osc_head(): rel=prev / rel=next on a paged results page');
$supports->reset();
$GLOBALS['page'] = 'search';

// Page 0 is the first page, so it has no predecessor.
$GLOBALS['searchPage']  = 0;
$GLOBALS['searchTotal'] = 3;
$h                      = $head();
check('the first page has no rel=prev', strpos($h, 'rel="prev"') === false);
check('...but does have rel=next, to page 2', strpos($h, '<link rel="next" href="https://example.test/search/cars/p2">') !== false);

$GLOBALS['searchPage'] = 1;
$h                     = $head();
check('page 2 points back to page 1, which has no page number', strpos($h, '<link rel="prev" href="https://example.test/search/cars">') !== false);
check('...and forward to page 3', strpos($h, '<link rel="next" href="https://example.test/search/cars/p3">') !== false);

$GLOBALS['searchPage'] = 2;
$h                     = $head();
check('the last page points back to page 2', strpos($h, '<link rel="prev" href="https://example.test/search/cars/p2">') !== false);
check('...and nowhere forward', strpos($h, 'rel="next"') === false);

// One page of results is not a sequence.
$GLOBALS['searchTotal'] = 1;
$GLOBALS['searchPage']  = 0;
$h                      = $head();
check('a single page of results gets neither', strpos($h, 'rel="next"') === false && strpos($h, 'rel="prev"') === false);

$GLOBALS['page']        = 'item';
$GLOBALS['searchPage']  = 1;
$GLOBALS['searchTotal'] = 3;
check('a listing page gets neither', strpos($head(), 'rel="next"') === false);

$GLOBALS['page'] = 'search';
$supports->reset();
osc_add_theme_support('head', array('pagination' => false));
check('a theme can opt out', strpos($head(), 'rel="next"') === false);
$supports->reset();
$GLOBALS['page'] = 'item';

harness_section('osc_language_attributes()');
$GLOBALS['locale']    = 'en_US';
$GLOBALS['direction'] = 'ltr';
pin('an underscore locale is written with a hyphen', 'lang="en-US" dir="ltr"', osc_language_attributes(false));
$GLOBALS['locale']    = 'ar_SA';
$GLOBALS['direction'] = 'rtl';
pin('and a right-to-left locale says so', 'lang="ar-SA" dir="rtl"', osc_language_attributes(false));
$GLOBALS['locale']    = 'en_US';
$GLOBALS['direction'] = 'ltr';

harness_section('osc_body_class(): one class per page');
$expected = array(
    'home'           => 'home',
    'search'         => 'search',
    'item'           => 'item',
    'item-post'      => 'item-post',
    'contact'        => 'contact',
    'login'          => 'login',
    'register'       => 'register',
    '404'            => 'error-404',
    'public-profile' => 'user-public-profile',
);
foreach ($expected as $page => $class) {
    // '404' is a numeric key, which PHP stores as an int.
    $GLOBALS['page'] = (string) $page;
    check($page . ' -> ' . $class, in_array($class, osc_body_class_list(), true));
}

harness_section('osc_body_class(): every account view also carries "user"');
foreach (array('dashboard', 'profile', 'items', 'alerts', 'change-email', 'change-password',
               'change-username') as $page) {
    $GLOBALS['page'] = $page;
    check($page . ' is inside the account area', in_array('user', osc_body_class_list(), true));
}
$GLOBALS['page'] = 'item';
check('a listing is not', !in_array('user', osc_body_class_list(), true));

harness_section('osc_body_class(): a static page carries its slug');
$GLOBALS['page']     = 'static-page';
$GLOBALS['pageSlug'] = 'about-us';
$classes             = osc_body_class_list();
check('page', in_array('page', $classes, true));
check('page-about-us', in_array('page-about-us', $classes, true));
// The slug is typed by an admin and lands in a class attribute.
$GLOBALS['pageSlug'] = 'About Us" onload="x';
check(
    'a slug that could break out of the attribute is flattened',
    in_array('page-about-us--onload--x', osc_body_class_list(), true)
);
$GLOBALS['pageSlug'] = '';

harness_section('osc_body_class(): who is looking');
$GLOBALS['page']     = 'home';
$GLOBALS['loggedIn'] = false;
check('logged-out', in_array('logged-out', osc_body_class_list(), true));
$GLOBALS['loggedIn'] = true;
$classes             = osc_body_class_list();
check('logged-in', in_array('logged-in', $classes, true));
check('and not both', !in_array('logged-out', $classes, true));
check('locale', in_array('lang-en-us', $classes, true));
check('theme', in_array('theme-fixture', $classes, true));
check('no rtl on a left-to-right locale', !in_array('rtl', $classes, true));
$GLOBALS['direction'] = 'rtl';
check('rtl', in_array('rtl', osc_body_class_list(), true));
$GLOBALS['direction'] = 'ltr';

harness_section('osc_body_class(): extras and the filter');
check('a caller string is added', in_array('mine', osc_body_class_list('mine'), true));
check('several are', in_array('two', osc_body_class_list('one two'), true));
$GLOBALS['bodyFilter'] = static function (array $classes): array {
    $classes[] = 'from-a-plugin';

    return $classes;
};
check('a plugin adds one', in_array('from-a-plugin', osc_body_class_list(), true));
$GLOBALS['bodyFilter'] = static fn (): string => 'not-a-list';
check('a filter returning junk is ignored', in_array('home', osc_body_class_list(), true));
$GLOBALS['bodyFilter'] = null;

harness_section('osc_body_class(): the attribute, not just the value');
$GLOBALS['page'] = 'home';
$attr            = osc_body_class('', false);
check('prints class="..."', strpos($attr, 'class="home ') === 0);

exit(harness_result());
