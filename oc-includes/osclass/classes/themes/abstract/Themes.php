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
 * Class Themes
 */
abstract class Themes
{
    private static $instance;
    protected $theme;
    protected $theme_url;
    protected $theme_path;
    protected $theme_exists;

    protected $scripts;
    protected $queue;
    protected $styles;

    protected $resolved;
    protected $unresolved;

    /**
     * Starts with empty script, queue and style lists.
     */
    public function __construct()
    {
        $this->scripts = array();
        $this->queue   = array();
        $this->styles  = array();
    }

    /**
     * Make $theme the current theme and recompute its path and URL.
     *
     * @param string $theme Theme directory name.
     *
     * @return void
     */
    public function setCurrentTheme($theme)
    {
        $this->theme = $theme;
        $this->setCurrentThemePath();
        $this->setCurrentThemeUrl();
    }

    /**
     * Resolve the current theme's absolute filesystem path, with a fallback when
     * the theme directory is missing.
     *
     * @return void
     */
    abstract protected function setCurrentThemePath();

    /* PUBLIC */

    /**
     * Resolve the current theme's public URL, with a fallback when the theme
     * directory is missing.
     *
     * @return void
     */
    abstract protected function setCurrentThemeUrl();

    /**
     * Current theme directory name, or null before one has been set.
     *
     * @return string|null
     */
    public function getCurrentTheme()
    {
        return $this->theme;
    }

    /**
     * Current theme's public URL with a trailing slash, or null before one has
     * been set.
     *
     * @return string|null
     */
    public function getCurrentThemeUrl()
    {
        return $this->theme_url;
    }

    /**
     * Current theme's absolute path with a trailing slash, or null before one has
     * been set.
     *
     * @return string|null
     */
    public function getCurrentThemePath()
    {
        return $this->theme_path;
    }

    /**
     * URL of the current theme's css/ directory.
     *
     * @return string
     */
    public function getCurrentThemeStyles()
    {
        return $this->theme_url . 'css/';
    }

    /**
     * URL of the current theme's js/ directory.
     *
     * @return string
     */
    public function getCurrentThemeJs()
    {
        return $this->theme_url . 'js/';
    }
}

/* file end: ./oc-includes/osclass/Themes.php */
