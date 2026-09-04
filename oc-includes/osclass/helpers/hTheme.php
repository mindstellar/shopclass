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
 * Whether the active theme can render $view: it ships the file, or it named the
 * view in its 'views' declaration -- a view it renders itself, from a plugin or
 * built at runtime, with no file of that name on disk.
 *
 * The active theme only. A parent theme and core's own fallback theme answer for
 * a theme the visitor is not using; osc_locate_template() walks those separately.
 *
 * @param string $view e.g. 'user-profile.php'
 */
function osc_theme_provides(string $view): bool
{
    if ($view === '' || strpos($view, '..') !== false || strpos($view, "\0") !== false) {
        return false;
    }

    if (file_exists(WebThemes::newInstance()->getCurrentThemePath() . $view)) {
        return true;
    }

    $declared = \mindstellar\theme\ThemeViews::declared(osc_theme_supports('views'));

    return in_array(\mindstellar\theme\ThemeViews::normalize($view), $declared, true);
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
    $base = WebThemes::newInstance()->getCurrentThemePath();

    $declared = osc_theme_supports('chrome');
    if (is_array($declared) && isset($declared['header'], $declared['footer'])) {
        $pair = osc_theme_chrome_pair($base, (string) $declared['header'], (string) $declared['footer']);
        if ($pair !== null) {
            return $pair;
        }
    }

    foreach (array('', 'common/') as $dir) {
        $pair = osc_theme_chrome_pair($base, $dir . 'header.php', $dir . 'footer.php');
        if ($pair !== null) {
            return $pair;
        }
    }

    return null;
}

/**
 * One candidate chrome pair, resolved against $base, or null unless both files
 * exist inside the theme. Relative paths only -- a declared '../' or an absolute
 * path would let a theme point core's include at any file on disk.
 *
 * @return array{header:string,footer:string}|null
 */
function osc_theme_chrome_pair(string $base, string $header, string $footer): ?array
{
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

    $themePath = WebThemes::newInstance()->getCurrentThemePath();
    if ($themeView !== '' && file_exists($themePath . $themeView)) {
        require $themePath . $themeView;

        return;
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
