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
 * Class AdminThemes
 */
class AdminThemes extends Themes
{
    private static $instance;

    public function __construct()
    {
        parent::__construct();
        $this->setCurrentTheme(osc_admin_theme());
    }

    /**
     * @return \AdminThemes
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function setCurrentThemeUrl()
    {
        if ($this->theme_exists) {
            $this->theme_url = osc_admin_base_url() . 'themes/' . $this->theme . '/';
        } else {
            $this->theme_url = osc_admin_base_url() . 'gui/';
        }
    }

    public function setCurrentThemePath()
    {
        if (file_exists(osc_admin_base_path() . 'themes/' . $this->theme . '/')) {
            $this->theme_exists = true;
            $this->theme_path   = osc_admin_base_path() . 'themes/' . $this->theme . '/';
        } else {
            $this->theme_exists = false;
            $this->theme_path   = osc_admin_base_path() . 'gui/';
        }
    }
}

/* file end: ./oc-includes/osclass/AdminThemes.php */
