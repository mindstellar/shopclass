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
        $fromRoute = false;
        if (Params::existParam('route')) {
            $routes = Rewrite::newInstance()->getRoutes();
            $rid    = Params::getParam('route');
            $file   = '../';
            if (isset($routes[$rid]['file'])) {
                $file      = $routes[$rid]['file'];
                $user_menu = $routes[$rid]['user_menu'];
                $fromRoute = true;
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

        // check if the file exists — a route may point at a plugin file or a file in the theme's
        // plugins/ folder. A route registered via osc_add_route() (trusted, PHP-level) may ALSO
        // point at a file in the theme root, so a theme can serve its own controllers without a
        // parallel router; the deprecated, request-controlled ?file= param is NOT granted the
        // theme-root path and stays limited to the plugins directories as before. The traversal /
        // admin-folder guard above applies to every branch. $file may also name a registered
        // render target (see osc_register_render_target()): the request supplies only an id
        // there, never a path, so it carries none of the traversal risk the checks above guard.
        if (!file_exists(osc_plugins_path() . $file)
            && !file_exists(osc_themes_path() . osc_theme() . '/plugins/' . $file)
            && !($fromRoute && file_exists(osc_themes_path() . osc_theme() . '/' . $file))
            && osc_render_target($file) === null
        ) {
            $this->do404();

            return;
        }

        osc_run_hook('custom_controller');

        $this->_exportVariableToView('file', $file);
        if ($user_menu) {
            if (osc_is_web_user_logged_in()) {
                Params::setParam('in_user_menu', true);
                $this->doView(osc_locate_template(array('user-custom.php'), 'user-custom'));
            } else {
                $this->redirectTo(osc_user_login_url());
            }
        } else {
            $this->doView(osc_locate_template(array('custom.php'), 'custom'));
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
        if (!osc_gui_account_view($file)) {
            osc_current_web_theme_path($file);
        }
        Session::newInstance()->_clearVariables();
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebCustom.php */
