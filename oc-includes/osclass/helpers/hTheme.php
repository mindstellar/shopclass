<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Gets urls for current theme administrations options
 *
 * @param string $file must be a relative path, from ABS_PATH
 *
 * @return string
 */
function osc_admin_render_theme_url($file = '')
{
    return osc_admin_base_url(true) . '?page=appearance&action=render&file=' . $file;
}

/**
 * Declare that the active theme supports $feature.
 *
 * Called from a theme's functions.php, which WebThemes::loadActive() requires
 * after these helpers are defined. Declaring is always optional: a feature
 * nobody registers reads as unsupported and core falls back to what it did
 * before.
 *
 * @param string $feature Slug, [a-z0-9_-]+, max 60 chars.
 * @param mixed  $args    Feature arguments, or true for a bare flag.
 */
function osc_add_theme_support(string $feature, $args = true): void
{
    \mindstellar\theme\ThemeSupports::instance()->add($feature, $args);
}

/**
 * Arguments the active theme declared for $feature, or false when it declared
 * nothing. Callers must treat false as "do what we did before", never as an
 * error.
 *
 * @return mixed
 */
function osc_theme_supports(string $feature)
{
    return \mindstellar\theme\ThemeSupports::instance()->get($feature);
}

/**
 * @param string $feature
 */
function osc_remove_theme_support(string $feature): void
{
    \mindstellar\theme\ThemeSupports::instance()->remove($feature);
}

/**
 * Every view name that is spoken for: the ones core asks any theme for, plus the
 * ones the active theme declared with
 * osc_add_theme_support('views', array('user-wishlist', 'template-promo')).
 *
 * A static page's internal name becomes a URL segment, so a page slugged
 * "contact" would shadow the contact route. This is the set the admin page
 * editor refuses.
 *
 * A theme that declares nothing gets core's list exactly as before; a
 * declaration only ever adds.
 *
 * @return string[] names without a directory and without .php
 */
function osc_theme_view_names(): array
{
    return \mindstellar\theme\ThemeViews::reserved(osc_theme_supports('views'));
}

/**
 * The theme stack a view is resolved against, as absolute directory paths:
 * active theme, then its parent when it declares one, then the bundled fallback
 * theme core keeps for views nobody else supplies.
 *
 * The same three places osc_current_web_theme_path() walks, without its side
 * effect of switching the active theme as it goes -- this only answers a
 * question. Cached per active theme: a request renders one theme, and the admin
 * theme previewer picks its theme before anything is located.
 *
 * @return string[] existing directories, each ending in a separator
 */
function osc_theme_template_paths(): array
{
    static $cache = array();

    $themes = WebThemes::newInstance();
    $active = $themes->getCurrentThemePath();
    $key    = (string) $themes->getCurrentTheme();

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $paths = array();
    if ($active !== '' && is_dir($active)) {
        $paths[] = $active;
    }

    $info = $themes->loadThemeInfo($themes->getCurrentTheme());
    if (is_array($info) && isset($info['template']) && $info['template'] !== ''
        && preg_match('/^[a-zA-Z0-9._-]+$/', $info['template'])
    ) {
        $parent = osc_themes_path() . $info['template'] . '/';
        if (is_dir($parent)) {
            $paths[] = $parent;
        }
    }

    $fallback = osc_content_path() . 'themes/storefront/';
    if (is_dir($fallback)) {
        $paths[] = $fallback;
    }

    $cache[$key] = array_values(array_unique($paths));

    return $cache[$key];
}

/**
 * The first view in $candidates that some theme in the stack can render.
 *
 * Ordered most specific first: array('item-12.php', 'item.php') renders the
 * category's own view where a theme ships one and the generic listing view
 * everywhere else. Candidate order is resolved *within* one theme before moving
 * on to the next, so a theme's own generic view always beats a fallback theme's
 * specific one -- otherwise adding a candidate could hand a page to a theme the
 * visitor is not using.
 *
 * Returns a **view name**, not a path: rendering goes through
 * osc_current_web_theme_path(), which also switches the theme's own asset URLs
 * to whichever theme in the stack answered. When nothing matches, the last
 * candidate comes back, so a caller behaves exactly as it did when it named one
 * view and no theme had it.
 *
 * Plugins and themes reshape the list through the `template_candidates` filter,
 * which receives the candidates and a context slug ('item', 'search', 'page', …).
 * This is the seam that lets a theme add a view without a core patch.
 *
 * Only files count here. A name declared through
 * osc_add_theme_support('views', …) with no file behind it reserves the name but
 * gives core nothing to require -- the theme renders that view itself.
 *
 * @param string[]|string $candidates ordered, most specific first
 * @param string          $context    route slug passed to the filter
 */
function osc_locate_template($candidates, string $context = ''): string
{
    if (is_string($candidates)) {
        $candidates = array($candidates);
    }
    if (!is_array($candidates)) {
        $candidates = array();
    }

    // A filter that hands back something other than a list is ignored rather than
    // obeyed: it must not be able to blank a page.
    $filtered = osc_apply_filter('template_candidates', $candidates, $context);
    if (is_array($filtered)) {
        $candidates = $filtered;
    }

    $clean = array();
    foreach ($candidates as $view) {
        if (!is_string($view)) {
            continue;
        }
        $view = trim($view);
        if ($view === '' || strpos($view, '..') !== false || strpos($view, "\0") !== false) {
            continue;
        }
        if ($view[0] === '/' || preg_match('#^[a-zA-Z]:#', $view)) {
            continue;
        }
        $clean[] = $view;
    }

    if ($clean === array()) {
        return '';
    }

    foreach (osc_theme_template_paths() as $base) {
        foreach ($clean as $view) {
            if (file_exists($base . $view)) {
                return $view;
            }
        }
    }

    return $clean[count($clean) - 1];
}

/**
 * The active theme's page chrome: the view that opens the document and the one
 * that closes it, as absolute paths, or null when the theme has none.
 *
 * Resolution, first hit wins:
 *   1. declared -- osc_add_theme_support('chrome', ['header' => …, 'footer' => …])
 *   2. the root pair       header.php        + footer.php        (bender, shopclass)
 *   3. the common/ pair    common/header.php + common/footer.php (storefront)
 *
 * Both halves must exist. A theme with a header and no footer is not chrome:
 * half a page is worse than a self-contained one.
 *
 * Deliberately the *active* theme only, not osc_current_web_theme_path()'s walk
 * onto a parent theme and then storefront -- that walk answers for a theme the
 * visitor is not using.
 *
 * @return array{header:string,footer:string}|null
 */
function osc_theme_chrome(): ?array
{
    $themes = WebThemes::newInstance();
    $bases  = array($themes->getCurrentThemePath());

    // A declared parent theme is part of the active theme's own identity, so a
    // child that ships no chrome inherits its parent's -- the same walk
    // osc_theme_template_paths() makes for views. The bundled fallback theme is
    // deliberately NOT here: it has never heard of this site's design, and core's
    // own shell is the right answer when nothing in the active lineage answers.
    $info = $themes->loadThemeInfo($themes->getCurrentTheme());
    if (is_array($info) && isset($info['template']) && $info['template'] !== ''
        && preg_match('/^[a-zA-Z0-9._-]+$/', $info['template'])
    ) {
        $parent = osc_themes_path() . $info['template'] . '/';
        if (is_dir($parent)) {
            $bases[] = $parent;
        }
    }

    // One candidate pair, or null unless both halves exist inside the theme.
    // Relative paths only: a declared '../' or an absolute path would let a theme
    // point core's include at any file on disk.
    $pair = static function (string $header, string $footer, string $base): ?array {
        foreach (array($header, $footer) as $rel) {
            if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
                return null;
            }
            if ($rel[0] === '/' || preg_match('#^[a-zA-Z]:#', $rel)) {
                return null;
            }
        }
        if (!file_exists($base . $header) || !file_exists($base . $footer)) {
            return null;
        }

        return array('header' => $base . $header, 'footer' => $base . $footer);
    };

    $declared = osc_theme_supports('chrome');

    foreach ($bases as $base) {
        if (is_array($declared) && isset($declared['header'], $declared['footer'])) {
            $found = $pair((string) $declared['header'], (string) $declared['footer'], $base);
            if ($found !== null) {
                return $found;
            }
        }

        foreach (array('', 'common/') as $dir) {
            $found = $pair($dir . 'header.php', $dir . 'footer.php', $base);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * Core's fallback for a page outside the account section: the plugin mount and
 * the seller-contact form. Same three steps as osc_gui_account_view() -- the
 * theme's own view, else core's partial inside the theme's chrome, else core's
 * shell -- kept separate from it because these are not account pages and the
 * account nav has no business on them.
 *
 * Returns false for a view core has no page for, so the caller falls through to
 * whatever it did before.
 */
function osc_gui_page_view(string $themeView): bool
{
    // Built per call: the headings are translated, and a constant would freeze
    // them in whichever locale loaded first.
    $pages = array(
        'custom.php'       => array('heading' => '', 'content' => 'custom'),
        'item-contact.php' => array(
            'heading' => _m('Contact the seller'),
            'content' => 'item-contact',
        ),
    );

    if (!isset($pages[$themeView])) {
        return false;
    }

    $contentFile = ABS_PATH . 'oc-includes/osclass/gui/' . $pages[$themeView]['content'] . '-content.php';
    if (!file_exists($contentFile)) {
        return false;
    }

    $opts = $pages[$themeView];
    unset($opts['content']);
    $opts['title'] = trim($opts['heading'] . ' — ' . osc_page_title(), ' —');

    osc_gui_view($themeView, $contentFile, $opts);

    return true;
}

/**
 * Whether the active theme can wrap a core-rendered page.
 */
function osc_theme_has_chrome(): bool
{
    return osc_theme_chrome() !== null;
}

/**
 * Render the theme's opening chrome. False when the theme has none, so a caller
 * can fall through to core's own shell.
 *
 * @return bool
 */
function osc_get_header(): bool
{
    $chrome = osc_theme_chrome();
    if ($chrome === null) {
        return false;
    }
    require $chrome['header'];

    return true;
}

/**
 * Render the theme's closing chrome. See osc_get_header().
 *
 * @return bool
 */
function osc_get_footer(): bool
{
    $chrome = osc_theme_chrome();
    if ($chrome === null) {
        return false;
    }
    require $chrome['footer'];

    return true;
}

/**
 * Render a page core owns, through whatever the active theme provides.
 *
 * Resolution, first hit wins:
 *   1. the active theme ships $themeView            -> the theme renders it, unchanged
 *   2. the theme exposes chrome (osc_theme_chrome()) -> chrome + core's content partial
 *   3. neither                                       -> core's own shell (gui/page.php)
 *
 * Step 1 deliberately checks the *active* theme only, not
 * osc_current_web_theme_path()'s walk onto a parent theme and then storefront:
 * those have never heard of these views and would render a blank page instead of
 * falling through to core.
 *
 * @param string $themeView   e.g. 'user-billing-wallet.php'
 * @param string $contentFile Absolute path to core's markup-only partial.
 * @param array  $opts        $oscPage keys for the shell; 'heading', 'title',
 *                            'intro' and 'tone' are also used on the chrome path.
 */
function osc_gui_view(string $themeView, string $contentFile, array $opts = array()): void
{
    require_once ABS_PATH . 'oc-includes/osclass/gui/page-fn.php';

    if ($themeView !== '') {
        $themes = WebThemes::newInstance();
        if (file_exists($themes->getCurrentThemePath() . $themeView)) {
            require $themes->getCurrentThemePath() . $themeView;

            return;
        }

        // A child theme inherits its parent's views, and osc_current_web_theme_path()
        // has always resolved them that way. What is deliberately NOT walked is the
        // setGuiTheme() fallback at the end of that walk: it is a bundled theme the
        // site is not running, and rendering its view here is the blank-or-foreign
        // page this fallback exists to replace.
        $info = $themes->loadThemeInfo($themes->getCurrentTheme());
        if (is_array($info) && !empty($info['template'])
            && preg_match('/^[a-zA-Z0-9._-]+$/', (string) $info['template'])
        ) {
            $parentPath = osc_themes_path() . $info['template'] . '/';
            if (file_exists($parentPath . $themeView)) {
                // Switches the theme URLs to the parent, exactly as the walk does,
                // so the parent's view loads the parent's assets.
                $themes->setParentTheme();
                require $themes->getCurrentThemePath() . $themeView;

                return;
            }
        }
    }

    if (!file_exists($contentFile)) {
        return;
    }

    $tone    = isset($opts['tone']) ? (string) $opts['tone'] : 'info';
    $heading = isset($opts['heading']) ? (string) $opts['heading'] : '';
    $intro   = isset($opts['intro']) ? (string) $opts['intro'] : '';

    if (osc_theme_has_chrome()) {
        // The sheet belongs in <head>, and every bundled theme runs this hook
        // there. The call after osc_get_header() covers a theme that does not:
        // print-once makes whichever ran second a no-op.
        osc_add_hook('header', static function () use ($tone) {
            osc_gui_print_style($tone);
        });
        osc_get_header();
        osc_gui_print_style($tone);
        echo '<div class="oe-page"><div class="oe-doc">';
        if ($heading !== '') {
            echo '<h1 class="oe-h1">' . osc_esc_html($heading) . '</h1>';
        }
        if ($intro !== '') {
            echo '<p>' . osc_esc_html($intro) . '</p>';
        }
        require $contentFile;
        echo '</div></div>';
        osc_get_footer();

        return;
    }

    ob_start();
    require $contentFile;
    $content = ob_get_clean();
    if ($intro !== '') {
        $content = '<p>' . osc_esc_html($intro) . '</p>' . $content;
    }

    $oscPage = array_merge(
        array(
            'layout'    => 'document',
            'tone'      => $tone,
            'role'      => 'main',
            'lang'      => str_replace('_', '-', osc_current_user_locale()),
            'brandName' => osc_page_title(),
            'homeUrl'   => osc_base_url(),
        ),
        $opts,
        array('body' => $content)
    );

    require ABS_PATH . 'oc-includes/osclass/gui/page.php';
}

/**
 * Render core's own fallback page for one of the account and auth views.
 *
 * Core has a content partial for every view the account section routes to, so a
 * theme that ships none of them still gets a working, styled page instead of a
 * blank one. Resolution is osc_gui_view()'s: the theme's own view wins, then the
 * theme's chrome around core's partial, then core's standalone shell.
 *
 * Returns false when core has no partial for $themeView, so the caller falls
 * through to its ordinary osc_current_web_theme_path() render.
 *
 * The class vocabulary the partials emit is documented for theme authors in
 * docs/site/developers/account-pages.md and cannot be renamed once released.
 *
 * @param string $themeView view filename, e.g. 'user-login.php'
 */
function osc_gui_account_view(string $themeView): bool
{
    // Headings are user-visible, so the table is built per call rather than held
    // in a constant: a constant would freeze them in whatever locale loaded first.
    $pages = array(
        'user-dashboard.php'       => array('heading' => _m('Dashboard')),
        'user-items.php'           => array('heading' => _m('Your listings')),
        'user-alerts.php'          => array('heading' => _m('Alerts')),
        'user-profile.php'         => array('heading' => _m('Your profile')),
        // One page, three routes. Each is a single setting answering the same
        // question -- how do I sign in -- so they share a destination rather than
        // being three near-identical pages to hunt through. The routes stay: a
        // bookmark or a theme's own link still resolves, to its own section.
        'user-change_email.php'    => array('heading' => _m('Sign-in details'), 'content' => 'user-signin'),
        'user-change_password.php' => array('heading' => _m('Sign-in details'), 'content' => 'user-signin'),
        'user-change_username.php' => array('heading' => _m('Sign-in details'), 'content' => 'user-signin'),
        'user-login.php'           => array('heading' => _m('Sign in')),
        'user-register.php'        => array('heading' => _m('Create an account')),
        'user-recover.php'         => array('heading' => _m('Reset your password')),
        'user-forgot_password.php' => array('heading' => _m('Choose a new password')),
        'user-public-profile.php'  => array('heading' => (string) osc_user_name()),
        'user-custom.php'          => array('heading' => _m('Your account')),
        'user-delete_account.php'  => array(
            'heading' => _m('Delete your account'),
            'tone'    => 'danger',
            'intro'   => _m('This page has not deleted the account yet. Enter your password and click '
                            . 'Delete my account. Your listings and messages will be removed. This '
                            . 'cannot be undone.'),
        ),
    );

    if (!isset($pages[$themeView])) {
        return false;
    }

    // user-delete_account keeps its original partial path: it shipped in 6.3.0's
    // first phase and a theme may already include it through that name.
    // A page may serve more than one route, so the map names its partial where the
    // two differ. user-delete_account keeps its original path: it shipped in
    // 6.3.0's first phase and a theme may already include it through that name.
    if ($themeView === 'user-delete_account.php') {
        $contentFile = ABS_PATH . 'oc-includes/osclass/gui/user-delete_account-content.php';
    } else {
        $partial     = $pages[$themeView]['content'] ?? basename($themeView, '.php');
        $contentFile = ABS_PATH . 'oc-includes/osclass/gui/account/' . $partial . '-content.php';
    }

    if (!file_exists($contentFile)) {
        return false;
    }

    $opts = $pages[$themeView];
    unset($opts['content']);
    $opts['title'] = trim($opts['heading'] . ' — ' . osc_page_title(), ' —');

    osc_gui_view($themeView, $contentFile, $opts);

    return true;
}

/**
 * The document's language attributes: `lang="en-US" dir="ltr"`.
 *
 * Every theme has reimplemented this line, and each one has to remember that a
 * locale code is stored with an underscore and written with a hyphen, and that
 * dir must be emitted for a right-to-left locale or the whole page reads
 * backwards. Filterable through `language_attributes`.
 *
 * @param bool $echo print as well as return
 */
function osc_language_attributes(bool $echo = true): string
{
    $lang = str_replace('_', '-', (string) osc_current_user_locale());
    $dir  = osc_locale_text_direction() === 'rtl' ? 'rtl' : 'ltr';

    $attrs = 'lang="' . osc_esc_html($lang) . '" dir="' . $dir . '"';
    $attrs = (string) osc_apply_filter('language_attributes', $attrs);

    if ($echo) {
        echo $attrs;
    }

    return $attrs;
}

/**
 * What page this is, as a list of class tokens for <body>.
 *
 * The account views all carry a shared `user` token alongside their own, so a
 * theme can style the whole signed-in area in one selector. Filterable through
 * `body_class`, which receives the computed list and whatever the caller passed.
 *
 * @param string|string[] $class extra classes from the caller
 *
 * @return string[] deduplicated, lowercase, [a-z0-9_-] only
 */
function osc_body_class_list($class = ''): array
{
    $classes = array();

    $pages = array(
        'home'               => 'osc_is_home_page',
        'search'             => 'osc_is_search_page',
        'search-category'    => 'osc_is_search_category_page',
        'item'               => 'osc_is_ad_page',
        'item-post'          => 'osc_is_publish_page',
        'item-edit'          => 'osc_is_edit_page',
        'item-contact'       => 'osc_is_item_contact_page',
        'contact'            => 'osc_is_contact_page',
        'custom'             => 'osc_is_custom_page',
        'login'              => 'osc_is_login_page',
        'register'           => 'osc_is_register_page',
        'recover'            => 'osc_is_recover_page',
        'forgot-password'    => 'osc_is_forgot_page',
        'user-public-profile' => 'osc_is_public_profile',
        'error-404'          => 'osc_is_404',
    );
    foreach ($pages as $name => $test) {
        if (function_exists($test) && $test()) {
            $classes[] = $name;
        }
    }

    $account = array(
        'user-dashboard'       => 'osc_is_user_dashboard',
        'user-profile'         => 'osc_is_user_profile',
        'user-items'           => 'osc_is_list_items',
        'user-alerts'          => 'osc_is_list_alerts',
        'user-change-email'    => 'osc_is_change_email_page',
        'user-change-password' => 'osc_is_change_password_page',
        'user-change-username' => 'osc_is_change_username_page',
    );
    foreach ($account as $name => $test) {
        if (function_exists($test) && $test()) {
            $classes[] = 'user';
            $classes[] = $name;
        }
    }

    if (osc_is_static_page()) {
        $classes[] = 'page';
        $slug = (string) osc_static_page_field('s_internal_name');
        if ($slug !== '') {
            $classes[] = 'page-' . $slug;
        }
    }

    $classes[] = osc_is_web_user_logged_in() ? 'logged-in' : 'logged-out';
    if (osc_locale_text_direction() === 'rtl') {
        $classes[] = 'rtl';
    }
    $classes[] = 'lang-' . str_replace('_', '-', (string) osc_current_user_locale());
    $classes[] = 'theme-' . (string) osc_theme();

    if (is_string($class)) {
        $class = preg_split('/\s+/', trim($class), -1, PREG_SPLIT_NO_EMPTY);
    }
    if (is_array($class)) {
        $classes = array_merge($classes, $class);
    }

    $filtered = osc_apply_filter('body_class', $classes, $class);
    if (is_array($filtered)) {
        $classes = $filtered;
    }

    $clean = array();
    foreach ($classes as $name) {
        if (!is_string($name)) {
            continue;
        }
        $name = preg_replace('/[^a-z0-9_-]/', '-', strtolower(trim($name)));
        if ($name !== '' && $name !== null) {
            $clean[] = $name;
        }
    }

    return array_values(array_unique($clean));
}

/**
 * The <body> class attribute: `<body <?php osc_body_class(); ?>>`.
 *
 * Prints the whole attribute, not just the value, so a page with no classes at
 * all prints nothing rather than an empty one.
 *
 * @param string|string[] $class extra classes from the caller
 * @param bool            $echo  print as well as return
 */
function osc_body_class($class = '', bool $echo = true): string
{
    $classes = osc_body_class_list($class);
    $attr    = $classes === array() ? '' : 'class="' . osc_esc_html(implode(' ', $classes)) . '"';

    if ($echo) {
        echo $attr;
    }

    return $attr;
}

/**
 * Everything core has to say inside <head>, followed by the `header` hook that
 * carries enqueued styles, scripts and whatever plugins add.
 *
 * Core could never put a tag in <head> on its own: the head belonged to the
 * theme, which is why a page core rendered had no title, description or
 * canonical unless the theme happened to supply one. A theme that calls this
 * hands that back.
 *
 * Parts are opt-out, so a theme keeping its own <title> declares:
 *
 *     osc_add_theme_support('head', array('title' => false));
 *
 * The declaration is also how core knows a theme's head is core-managed; the
 * function itself works whether or not the theme declared anything.
 *
 * Runs the `header` hook itself -- a theme calling osc_head() must not also call
 * osc_run_hook('header'), or every enqueued asset is printed twice.
 */
function osc_head(): void
{
    $args = osc_theme_supports('head');
    $want = static function (string $part) use ($args): bool {
        return !is_array($args) || !isset($args[$part]) || $args[$part] !== false;
    };

    if ($want('charset')) {
        echo '<meta charset="utf-8">' . PHP_EOL;
    }
    if ($want('viewport')) {
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . PHP_EOL;
    }
    if ($want('title')) {
        echo '<title>' . osc_esc_html((string) meta_title()) . '</title>' . PHP_EOL;
    }
    if ($want('description')) {
        $description = (string) meta_description();
        if ($description !== '') {
            echo '<meta name="description" content="' . osc_esc_html($description) . '">' . PHP_EOL;
        }
    }
    if ($want('keywords')) {
        $keywords = (string) meta_keywords();
        if ($keywords !== '') {
            echo '<meta name="keywords" content="' . osc_esc_html($keywords) . '">' . PHP_EOL;
        }
    }
    if ($want('canonical')) {
        $canonical = (string) osc_get_canonical();
        if ($canonical !== '') {
            echo '<link rel="canonical" href="' . osc_esc_html($canonical) . '">' . PHP_EOL;
        }
    }
    if ($want('feed')) {
        // The current search's feed on a results page, the site-wide one elsewhere.
        $feed = osc_is_search_page()
            ? osc_update_search_url(array('sFeed' => 'rss'))
            : osc_search_url(array('sFeed' => 'rss'));
        echo '<link rel="alternate" type="application/rss+xml" title="'
            . osc_esc_html(osc_page_title()) . '" href="' . osc_esc_html((string) $feed) . '">' . PHP_EOL;
    }

    // Enqueued styles and scripts, robots meta, and everything plugins add.
    osc_run_hook('header');
}

/**
 * Warn, in debug builds only, when the `header` hook runs more than once in a
 * request.
 *
 * osc_head() runs the hook itself, so a theme that calls osc_head() *and* keeps
 * its old osc_run_hook('header') loads every enqueued script and stylesheet
 * twice. Nothing about the page looks wrong, which is why it is worth a notice:
 * the symptom is duplicated assets, not a broken layout.
 *
 * Registered on the hook rather than inside osc_head(), so it sees the second
 * call whichever of the two made it. The registration lives in functions.php:
 * this file is required before hPlugins.php, so osc_add_hook() does not exist
 * yet, and the DB-free tests include it directly.
 */
function osc_head_hook_guard(): void
{
    static $runs = 0;

    if (++$runs > 1 && defined('OSC_DEBUG') && OSC_DEBUG) {
        trigger_error(
            "The 'header' hook has run {$runs} times this request. A theme calling both "
            . "osc_head() and osc_run_hook('header') enqueues every script and stylesheet twice; "
            . 'osc_head() already runs the hook.',
            E_USER_WARNING
        );
    }
}

/**
 * Register a named render target: an opaque id mapped to an absolute file path.
 * osc_render_file() checks this registry before its own filesystem lookups, so
 * core can expose a file outside the theme/plugin directories -- e.g. an
 * oc-includes/ partial -- to ?page=custom&file=<id>. The request only ever
 * supplies the id; the path is never request-controlled. Thin wrapper over
 * \mindstellar\theme\RenderTargetRegistry::register().
 *
 * @param string $id   Namespaced slug, [a-z0-9_-]+(/[a-z0-9_-]+)*, max 80 chars.
 * @param string $path Absolute path to an existing .php file.
 */
function osc_register_render_target(string $id, string $path): void
{
    \mindstellar\theme\RenderTargetRegistry::instance()->register($id, $path);
}

/**
 * Absolute path registered for $id, or null when nothing is registered under it.
 *
 * @param string $id
 *
 * @return string|null
 */
function osc_render_target(string $id): ?string
{
    return \mindstellar\theme\RenderTargetRegistry::instance()->get($id);
}

/**
 * Render the specified file
 *
 * @param string $file must be a relative path, from PLUGINS_PATH, or a registered
 *                      render target id (see osc_register_render_target())
 */
function osc_render_file($file = '')
{
    if ($file == '') {
        $file = __get('file');
    }
    // Clean $file to prevent hacking of some
    osc_sanitize_url($file);
    $file = str_replace(array(
                            "..\\",
                            '../'
                        ), '', str_replace('://', '', preg_replace('|http([s]*)|', '', $file)));

    $target = \mindstellar\theme\RenderTargetRegistry::instance()->get($file);
    if ($target !== null) {
        include $target;

        return;
    }

    if (file_exists(osc_themes_path() . osc_theme() . '/plugins/' . $file)) {
        include osc_themes_path() . osc_theme() . '/plugins/' . $file;
    } elseif (file_exists(osc_plugins_path() . $file)) {
        include osc_plugins_path() . $file;
    }
}

/**
 * Gets urls for render custom files in front-end
 *
 * @param string $file must be a relative path, from PLUGINS_PATH
 *
 * @return string
 */
function osc_render_file_url($file = '')
{
    osc_sanitize_url($file);
    $file = str_replace(array(
                            "..\\",
                            '../'
                        ), '', str_replace('://', '', preg_replace('|http([s]*)|', '', $file)));

    return osc_base_url(true) . '?page=custom&file=' . $file;
}

/**
 * Re-send the flash messages of the given section. Usefull for custom theme/plugins files.
 *
 * @param string $section
 */
function osc_resend_flash_messages($section = 'pubMessages')
{
    $messages = Session::newInstance()->_getMessage($section);
    if (is_array($messages)) {
        foreach ($messages as $message) {
            $message = Session::newInstance()->_getMessage($section);
            if (isset($message['msg'])) {
                if (isset($message['type']) && $message['type'] === 'info') {
                    osc_add_flash_info_message($message['msg'], $section);
                } elseif (isset($message['type']) && $message['type'] === 'ok') {
                    osc_add_flash_ok_message($message['msg'], $section);
                } else {
                    osc_add_flash_error_message($message['msg'], $section);
                }
            }
        }
    }
}

/**
 * Enqueue script
 *
 * @param string $id
 */
function osc_enqueue_script($id)
{
    Scripts::newInstance()->enqueueScript($id);
}

/**
 * Enqueue a block of inline JavaScript into the footer, after the file scripts.
 * The admin/front target is detected from the current request.
 *
 * @param string      $code         JavaScript wrapped in its own <script> tag
 * @param array|null  $dependencies registered script ids to enqueue alongside it
 * @param string|null $id           optional id; a repeated id is enqueued only once
 */
function osc_enqueue_script_code($code, $dependencies = null, $id = null)
{
    Scripts::enqueueScriptCode($code, $dependencies, defined('OC_ADMIN') && OC_ADMIN, $id);
}

/**
 * Remove script from the queue, so it will not be loaded
 *
 * @param string $id
 */
function osc_remove_script($id)
{
    Scripts::newInstance()->removeScript($id);
}

/**
 * Add script to be loaded
 *
 * @param $id           string Id to identify the script
 * @param $url          string url of the .js file
 * @param $dependencies mixed, could be an array or a string
 */
function osc_register_script($id, $url, $dependencies = null)
{
    Scripts::newInstance()->registerScript($id, $url, $dependencies);
}

/**
 * Remove script from the queue, so it will not be loaded
 *
 * @param string $id
 */
function osc_unregister_script($id)
{
    Scripts::newInstance()->unregisterScript($id);
}

/**
 * Print the HTML tags to make the script load
 */
function osc_load_scripts()
{
    Scripts::newInstance()->printScripts();
    if (defined('OC_ADMIN') && OC_ADMIN) {
        osc_run_hook('admin_scripts_loaded');
    } else {
        osc_run_hook('scripts_loaded');
    }
}

/**
 * Register style with dependencies
 *
 * @param $id           string Id to identify the style
 * @param $url          string url of the .css file
 * @param $dependencies mixed, could be an array or a string
 */
function osc_register_style($id, $url, $dependencies = null)
{
    Styles::newInstance()->register($id, $url, $dependencies);
}

/**
 * Remove style from the queue, so it will not be loaded
 *
 * @param string $id
 */
function osc_unregister_style($id)
{
    Styles::newInstance()->unregister($id);
}

/**
 * Add style to be loaded
 * If style is already registered only id is needed to enqueue style
 *
 * @param $id  string Id to identify the style
 * @param $url string|null Url of the .css file
 */
function osc_enqueue_style($id, $url = null)
{
    if ($url === null) {
        Styles::newInstance()->enqueue($id);
    } else {
        Styles::newInstance()->addStyle($id, $url);
    }
}

/**
 * Remove style from the queue, so it will not be loaded
 *
 * @param $id
 */
function osc_remove_style($id)
{
    Styles::newInstance()->removeStyle($id);
}

/**
 * Print the HTML tags to make the style load
 */
function osc_load_styles()
{
    Styles::newInstance()->printStyles();
}

/**
 * Public URL of a theme's screenshot, or a bundled placeholder when it has
 * none. Checks screenshot.png, then screenshot.jpg, then screenshot.webp on
 * disk, in that order — themes in the wild ship all three.
 *
 * @param string|null $theme defaults to the active theme
 *
 * @return string
 */
function osc_theme_screenshot_url($theme = null)
{
    if ($theme === null) {
        $theme = osc_theme();
    }

    $asset = _osc_theme_screenshot_asset($theme);
    $url   = $asset !== null
        ? osc_base_url() . 'oc-content/themes/' . $theme . '/' . $asset
        : osc_base_url() . 'oc-admin/themes/modern/images/placeholder-theme.svg';

    return osc_apply_filter('theme_screenshot_url', $url, $theme);
}

/**
 * Whether a theme has a screenshot on disk, as opposed to the fallback
 * placeholder osc_theme_screenshot_url() returns.
 *
 * @param string|null $theme defaults to the active theme
 *
 * @return bool
 */
function osc_theme_has_screenshot($theme = null)
{
    if ($theme === null) {
        $theme = osc_theme();
    }

    return _osc_theme_screenshot_asset($theme) !== null;
}

/**
 * Finds the screenshot filename for a theme on disk.
 *
 * @param string $theme
 *
 * @return string|null the filename found (relative to the theme folder), or null
 */
function _osc_theme_screenshot_asset($theme)
{
    if (!is_string($theme) || $theme === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $theme)) {
        return null;
    }

    foreach (array('screenshot.png', 'screenshot.jpg', 'screenshot.webp') as $asset) {
        if (file_exists(osc_themes_path() . $theme . '/' . $asset)) {
            return $asset;
        }
    }

    return null;
}

/**
 * @param        $id
 * @param        $name
 * @param        $options
 * @param string $class
 */
function osc_print_bulk_actions($id, $name, $options, $class = '')
{
    echo '<select id="' . $id . '" name="' . $name . '" ' . ($class != '' ? 'class="form-select ' . $class . '"' : 'form-select') . '>';
    foreach ($options as $o) {
        $opt   = '';
        $label = '';
        foreach ($o as $k => $v) {
            if ($k !== 'label') {
                $opt .= $k . '="' . $v . '" ';
            } else {
                $label = $v;
            }
        }
        echo '<option ' . $opt . '>' . $label . '</option>';
    }
    echo '</select>';
}

/* file end: ./oc-includes/osclass/hTheme.php */
