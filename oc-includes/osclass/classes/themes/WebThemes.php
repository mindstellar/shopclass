<?php
/*
 * This file is part of Osclass (Mindstellar).
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
 * Class WebThemes
 */
class WebThemes extends Themes
{
    private static $instance;

    private $pages = array(
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
        'user-forgot_password',
        'user-items',
        'user-login',
        'user-profile',
        'user-recover',
        'user-register',
    );
    /**
     * @var string
     */
    private $path;

    public function __construct()
    {
        parent::__construct();
        $this->path = osc_themes_path();
    }
    public static function init()
    {
        Plugins::runHook('before_init_web_theme');
        self::newInstance()->loadActive();
        Plugins::runHook('after_init_web_theme');
    }

    private function loadActive()
    {
        if (Params::getParam('theme') != '' && Session::newInstance()->_get('adminId') != '') {
            $this->setCurrentTheme(Params::getParam('theme'));
        } else {
            $this->setCurrentTheme(osc_theme());
        }

        $functions_path = $this->getCurrentThemePath() . 'functions.php';
        if (file_exists($functions_path)) {
            require_once $functions_path;
        }

        $info = $this->loadThemeInfo($this->theme);
        if (isset($info['template']) && $info['template'] != '') {
            //$this->setCurrentTheme($info['template']);
            $parent_functions_path = osc_base_path() . 'oc-content/themes/' . $info['template'] . '/functions.php';
            if (file_exists($parent_functions_path)) {
                require_once $parent_functions_path;
            }
        }
    }
    /**
     *
     * @param  $theme
     *
     * @return array|bool
     */
    public function loadThemeInfo($theme)
    {
        $path = $this->path . $theme . '/index.php';
        if (!file_exists($path)) {
            return false;
        }

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
        $info['filename'] = $path;
        $info['int_name'] = $theme;

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

        return $result;
    }

    /**
     * @return \WebThemes
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /* PUBLIC */

    public function setCurrentThemePath()
    {
        if (file_exists($this->path . $this->theme . '/')) {
            $this->theme_exists = true;
            $this->theme_path   = $this->path . $this->theme . '/';
        } else {
            $this->theme_exists = false;
            $this->theme_path   = osc_content_path() . 'themes/bender/';
        }
    }

    public function setCurrentThemeUrl()
    {
        if ($this->theme_exists) {
            $this->theme_url =
                osc_apply_filter('theme_url', osc_base_url() . str_replace(osc_base_path(), '', $this->theme_path));
        } else {
            $this->theme_url = osc_apply_filter('theme_url', osc_base_url() . 'oc-content/themes/bender/');
        }
    }

    /**
     * @param $path
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

    public function setGuiTheme()
    {
        $this->theme = '';

        $this->theme_exists = false;
        $this->theme_path   = osc_content_path() . 'themes/bender/';
        $this->theme_url    = osc_base_url() . 'oc-content/themes/bender/';

        $functions_path = $this->getCurrentThemePath() . 'functions.php';
        if (file_exists($functions_path)) {
            require_once $functions_path;
        }
    }

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
     * @return array
     */
    public function getListThemes()
    {
        $themes = array();
        $dir    = opendir($this->path);
        while ($file = readdir($dir)) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $file)
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
     * @param $internal_name
     *
     * @return bool
     */
    public function isValidPage($internal_name)
    {
        return !in_array($internal_name, $this->pages);
    }

    /**
     * @param null $theme
     *
     * @return array
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
