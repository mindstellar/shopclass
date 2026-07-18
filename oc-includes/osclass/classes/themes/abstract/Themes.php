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

    public function __construct()
    {
        $this->scripts = array();
        $this->queue   = array();
        $this->styles  = array();
    }

    /**
     * @param $theme
     */
    public function setCurrentTheme($theme)
    {
        $this->theme = $theme;
        $this->setCurrentThemePath();
        $this->setCurrentThemeUrl();
    }

    abstract protected function setCurrentThemePath();

    /* PUBLIC */

    abstract protected function setCurrentThemeUrl();

    public function getCurrentTheme()
    {
        return $this->theme;
    }

    public function getCurrentThemeUrl()
    {
        return $this->theme_url;
    }

    public function getCurrentThemePath()
    {
        return $this->theme_path;
    }

    /**
     * @return string
     */
    public function getCurrentThemeStyles()
    {
        return $this->theme_url . 'css/';
    }

    /**
     * @return string
     */
    public function getCurrentThemeJs()
    {
        return $this->theme_url . 'js/';
    }
}

/* file end: ./oc-includes/osclass/Themes.php */
