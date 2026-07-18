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
 * Class CWebCustom
 */
class CWebCustom extends BaseModel
{
    public function __construct()
    {
        parent::__construct();
        //specific things for this class
        osc_run_hook('init_custom');
    }

    //Business Layer...
    public function doModel()
    {
        $user_menu = false;
        if (Params::existParam('route')) {
            $routes = Rewrite::newInstance()->getRoutes();
            $rid    = Params::getParam('route');
            $file   = '../';
            if (isset($routes[$rid]['file'])) {
                $file      = $routes[$rid]['file'];
                $user_menu = $routes[$rid]['user_menu'];
            }
        } else {
            // DEPRECATED: Disclosed path in URL is deprecated, use routes instead
            // This will be REMOVED in 3.4
            $file = Params::getParam('file');
        }

        // valid file?
        if (strpos($file, '../') !== false || strpos($file, '..\\') !== false
            || stripos($file, '/admin/') !== false
        ) { //If the file is inside an "admin" folder, it should NOT be opened in frontend
            $this->do404();

            return;
        }

        // check if the file exists
        if (!file_exists(osc_plugins_path() . $file)
            && !file_exists(osc_themes_path() . osc_theme() . '/plugins/' . $file)
        ) {
            $this->do404();

            return;
        }

        osc_run_hook('custom_controller');

        $this->_exportVariableToView('file', $file);
        if ($user_menu) {
            if (osc_is_web_user_logged_in()) {
                Params::setParam('in_user_menu', true);
                $this->doView('user-custom.php');
            } else {
                $this->redirectTo(osc_user_login_url());
            }
        } else {
            $this->doView('custom.php');
        }
    }

    //hopefully generic...

    /**
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        osc_current_web_theme_path($file);
        Session::newInstance()->_clearVariables();
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebCustom.php */
