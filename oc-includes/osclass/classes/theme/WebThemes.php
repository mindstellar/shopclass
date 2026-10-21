<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Class WebThemes
 */
class WebThemes extends Themes
{
    use \mindstellar\base\SharedInstance;

    /**
     * @var string
     */
    private $path;

    /** @var array<string,array<string,mixed>|false> theme info by index.php path and mtime */
    private array $themeInfo = array();

    /**
     * Starts out looking for themes in oc-content/themes/.
     */
    public function __construct()
    {
        parent::__construct();
        $this->path = osc_themes_path();
    }
    /**
     * Load the active public theme, wrapped in the before/after init hooks.
     *
     * @return void
     */
    public static function init()
    {
        Plugins::runHook('before_init_web_theme');
        self::getInstance()->loadActive();
        Plugins::runHook('after_init_web_theme');
    }

    /**
     * Select the active theme -- a preview (see previewTheme()), otherwise the
     * configured one -- and require its functions.php, then the parent theme's
     * when it declares one.
     *
     * @return void
     */
    private function loadActive()
    {
        $this->setCurrentTheme($this->previewTheme() ?? osc_theme());

        // The API renders nothing, so it skips the theme's code unless a plugin asks for it.
        if (osc_is_api_request() && !osc_apply_filter('api_theme_functions_enabled', false)) {
            return;
        }

        $functions_path = $this->getCurrentThemePath() . 'functions.php';
        if (file_exists($functions_path)) {
            require_once $functions_path;
        }

        $info = $this->loadThemeInfo($this->theme);
        if (isset($info['template']) && $info['template'] !== ''
            && \mindstellar\utility\Validate::packageName((string) $info['template'])
            && $info['template'] !== $this->theme
        ) {
            $parent_functions_path = osc_base_path() . 'oc-content/themes/' . $info['template'] . '/functions.php';
            if (file_exists($parent_functions_path)) {
                // The parent fills in what the child left unsaid. Its declarations run
                // second, so without this marker the newest value wins and the parent
                // overrules the child on everything they both declare.
                $supports = \mindstellar\theme\ThemeSupports::getInstance();
                $supports->beginInherited();
                try {
                    require_once $parent_functions_path;
                } finally {
                    $supports->endInherited();
                }
            }
        }
    }

    /**
     * Whether this request may preview a theme: a signed-in admin, or any visitor on a demo site.
     */
    private static function previewAllowed(): bool
    {
        return \mindstellar\security\Demo::active() || Session::getInstance()->_get('adminId') != '';
    }

    /**
     * The theme a preview asks for: ?theme=<slug>, kept for the visit in the oc_theme
     * cookie. ?theme= with the active theme or nothing ends the preview.
     *
     * @return string|null null for the configured theme
     */
    private function previewTheme(): ?string
    {
        // Admin pages take their own ?theme= (activate, dark mode) and never change the preview.
        if ((defined('OC_ADMIN') && OC_ADMIN) || osc_is_api_request() || !self::previewAllowed()) {
            return null;
        }
        // The name picks the directory functions.php is required from, so it must be an installed theme.
        $installed = fn (string $slug): bool => $slug !== '' && in_array($slug, $this->getListThemes(), true);
        $kept      = (string) ($_COOKIE['oc_theme'] ?? '');

        if (!Params::existParam('theme')) {
            return $installed($kept) ? $kept : null;
        }

        $asked = Params::getParamString('theme');
        if ($installed($asked) && $asked !== osc_theme()) {
            // A browser-session cookie: it keeps the visitor off the shared cache, so it must not outlive the visit.
            if ($asked !== $kept) {
                Cookie::write('oc_theme', $asked, 0);
            }
            // Set for this request too, so its response is not marked cacheable.
            $_COOKIE['oc_theme'] = $asked;

            return $asked;
        }
        if (isset($_COOKIE['oc_theme'])) {
            Cookie::write('oc_theme', '', time() - 3600);
            unset($_COOKIE['oc_theme']);
        }

        return null;
    }

    /**
     * Header fields read out of a theme's index.php, falling back to the legacy
     * <theme>_theme_info() function. False when the theme has neither.
     *
     * @param string $theme Theme directory name.
     *
     * @return array<string,mixed>|false
     */
    public function loadThemeInfo($theme)
    {
        $path = $this->path . $theme . '/index.php';
        if (!file_exists($path)) {
            return false;
        }

        // A page asks several times, once per asset the theme lacks; a replaced file has a new mtime.
        return $this->themeInfo[$path . '|' . filemtime($path)] ??= $this->readThemeInfo((string) $theme, $path);
    }

    /**
     * @return array<string,mixed>|false
     */
    private function readThemeInfo(string $theme, string $path)
    {
        // NEW CODE FOR THEME INFO
        $s_info = file_get_contents($path);
        $info   = array();

        //For compatibility use theme_name
        if (preg_match('|Theme Name:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['name'] = trim($match[1]);
        } else {
            $info['name'] = '';
        }

        if (preg_match('|Theme Name:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['theme_name'] = trim($match[1]);
        } else {
            $info['theme_name'] = '';
        }

        if (preg_match('|Parent Theme:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['template'] = trim($match[1]);
        } else {
            $info['template'] = '';
        }

        if (preg_match('|Theme URI:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['theme_uri'] = trim($match[1]);
        } else {
            $info['theme_uri'] = '';
        }

        if (preg_match('|Theme update URI:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['theme_update_uri'] = trim($match[1]);
        } else {
            $info['theme_update_uri'] = '';
        }

        if (preg_match('|Description:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['description'] = trim($match[1]);
        } else {
            $info['description'] = '';
        }

        if (preg_match('|Version:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['version'] = trim($match[1]);
        } else {
            $info['version'] = '';
        }

        if (preg_match('|Author:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['author_name'] = trim($match[1]);
        } else {
            $info['author_name'] = '';
        }

        if (preg_match('|Author URI:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['author_url'] = trim($match[1]);
        } else {
            $info['author_url'] = '';
        }

        if (preg_match('|Widgets:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['locations'] = explode(',', str_replace(' ', '', $match[1]));
        } else {
            $info['locations'] = array();
        }

        if (preg_match('|Requires Shopclass:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['requires'] = trim($match[1]);
        } else {
            $info['requires'] = '';
        }

        if (preg_match('|Tested up to:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['tested_up_to'] = trim($match[1]);
        } else {
            $info['tested_up_to'] = '';
        }

        if (preg_match('|Requires PHP:([^\\r\\t\\n]*)|i', $s_info, $match)) {
            $info['requires_php'] = trim($match[1]);
        } else {
            $info['requires_php'] = '';
        }

        $manifest                 = \mindstellar\market\PackageManifest::read(dirname($path), $s_info);
        $info['modes']            = $manifest['modes'];
        $info['requires_plugins'] = $manifest['plugins'];
        $info['filename']         = $path;
        $info['int_name']         = $theme;

        if ($info['name'] != '') {
            return $info;
        }

        // OLD CODE INFO
        require_once $path;
        $fxName = $theme . '_theme_info';
        if (!function_exists($fxName)) {
            return false;
        }
        $result             = $fxName();
        $result['int_name'] = $theme;
        $manifest           = \mindstellar\market\PackageManifest::read(dirname($path), $s_info);
        $result += ['modes' => $manifest['modes'], 'requires_plugins' => $manifest['plugins']];

        return $result;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /* PUBLIC */

    /**
     * Point theme_path at the current theme's directory, falling back to the
     * bundled storefront theme when it is missing.
     *
     * @return void
     */
    public function setCurrentThemePath()
    {
        // A theme is a directory name, never a path. Belt and braces behind the
        // caller-side checks: nothing may resolve outside oc-content/themes/.
        if (\mindstellar\utility\Validate::packageName((string) $this->theme)
            && strpos((string) $this->theme, '..') === false
            && file_exists($this->path . $this->theme . '/')
        ) {
            $this->theme_exists = true;
            $this->theme_path   = $this->path . $this->theme . '/';
        } else {
            $this->theme_exists = false;
            $this->theme_path   = osc_content_path() . 'themes/storefront/';
        }
    }

    /**
     * Point theme_url at the current theme's directory, falling back to the
     * bundled storefront theme when it is missing. Filtered by 'theme_url'.
     *
     * @return void
     */
    public function setCurrentThemeUrl()
    {
        if ($this->theme_exists) {
            $this->theme_url =
                osc_apply_filter('theme_url', osc_base_url() . str_replace(osc_base_path(), '', $this->theme_path));
        } else {
            $this->theme_url = osc_apply_filter('theme_url', osc_base_url() . 'oc-content/themes/storefront/');
        }
    }

    /**
     * Change the directory themes are looked up in. False when $path does not
     * exist, in which case nothing changes.
     *
     * @param string $path
     *
     * @return bool
     */
    public function setPath($path)
    {
        if (file_exists($path)) {
            $this->path = $path;

            return true;
        }

        return false;
    }

    /**
     * Force the bundled storefront theme and require its functions.php.
     *
     * @return void
     */
    public function setGuiTheme()
    {
        $this->theme = '';

        $this->theme_exists = false;
        $this->theme_path   = osc_content_path() . 'themes/storefront/';
        $this->theme_url    = osc_base_url() . 'oc-content/themes/storefront/';

        $functions_path = $this->getCurrentThemePath() . 'functions.php';
        if (file_exists($functions_path)) {
            require_once $functions_path;
        }
    }

    /**
     * Switch to the parent theme declared by the current child theme's header.
     *
     * @return void
     */
    public function setParentTheme()
    {
        $info = $this->loadThemeInfo($this->theme);

        $this->theme = $info['template'];

        $this->theme_exists = true;
        $this->theme_path   = $this->path . $this->theme . '/';
        $this->theme_url    = osc_base_url() . str_replace(osc_base_path(), '', $this->theme_path);

        //$functions_path = $this->getCurrentThemePath() . 'functions.php';
        //if( file_exists($functions_path) ) {
        //  require_once $functions_path;
        //}
    }

    /**
     * This function returns an array of themes (those copied in the oc-content/themes folder)
     *
     * @return string[] theme directory names
     */
    public function getListThemes()
    {
        $themes = array();
        $dir    = opendir($this->path);
        while ($file = readdir($dir)) {
            // The installer's own name rule, so any folder it accepts is listed.
            // It refuses `.` and `..`.
            if (\mindstellar\utility\Validate::packageName($file)
                && file_exists($this->path . '/' . $file . '/index.php')
                && $this->loadThemeInfo($file)
            ) {
                $themes[] = $file;
            }
        }
        closedir($dir);

        return $themes;
    }

    /**
     * Whether $internal_name is free for a static page to take.
     *
     * The reserved set is core's own view vocabulary plus anything the active
     * theme declared through osc_add_theme_support('views', …), so a theme with
     * views core has never heard of can protect their names without a core patch.
     *
     * @param string $internal_name
     *
     * @return bool
     */
    public function isValidPage($internal_name)
    {
        return !in_array($internal_name, osc_theme_view_names(), true);
    }

    /**
     * Filenames of the template-*.php files a theme ships, defaulting to the
     * current theme.
     *
     * @param string|null $theme Theme directory name.
     *
     * @return string[]
     */
    public function getAvailableTemplates($theme = null)
    {
        if ($theme == null) {
            $theme = $this->theme;
        }

        $templates = array();
        $dir       = opendir($this->path . $theme . '/');
        while ($file = readdir($dir)) {
            if (preg_match('/^template-[a-zA-Z0-9_\.]+$/', $file)) {
                $templates[] = $file;
            }
        }
        closedir($dir);

        return $templates;
    }
}

/* file end: ./oc-includes/osclass/WebThemes.php */
