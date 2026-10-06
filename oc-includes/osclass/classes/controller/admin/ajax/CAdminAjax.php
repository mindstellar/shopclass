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

use mindstellar\admin\ajax\AjaxRegistry;
use mindstellar\utility\AjaxResponse;

define('IS_AJAX', true);

/**
 * The admin ajax endpoint (?page=ajax&action=...). Each action is answered by a handler
 * under mindstellar\admin\ajax, looked up in AjaxRegistry.
 */
class CAdminAjax extends AdminSecBaseModel
{
    /**
     * Mark the request as ajax and reduce a moderator to the handful of actions their
     * role may reach.
     */
    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
        if ($this->isModerator() && !AjaxRegistry::moderatorMay($this->action)) {
            $this->action = 'error_permissions';
        }
    }

    /**
     * Dispatch the requested ajax action, then drop the session's kept form state.
     *
     * @return void
     */
    public function doModel()
    {
        $route = AjaxRegistry::route($this->action);
        if ($route === null) {
            AjaxResponse::json(array('error' => __('no action defined')));
        } else {
            if ($route['csrf']) {
                osc_csrf_check();
            }
            $handler = new $route['handler']($this);
            $handler->{$route['method']}($this->action);
        }
        // clear all keep variables into session
        Session::getInstance()->_dropKeepForm();
        Session::getInstance()->_clearVariables();
    }

    /**
     * On a demo install, refuse with a flash and redirect.
     *
     * @param string $redirectUrl
     *
     * @return bool true when refused
     */
    public function refuseDemo($redirectUrl)
    {
        return $this->refuseOnDemo($redirectUrl);
    }

    /**
     * Run a plugin's ajax file. It runs here so `$this` is still the controller, as it
     * always was for these files.
     *
     * @param string $file     the requested path, relative to the plugins folder
     * @param string $resolved its checked absolute path
     *
     * @return void
     */
    public function runPluginFile($file, $resolved)
    {
        require_once $resolved;
    }

    /**
     * Render an admin theme template. Ajax actions answer with JSON, so this is only
     * reached by the few that draw an iframe.
     *
     * @param string $file Path relative to the admin theme
     *
     * @return void
     */
    public function doView($file)
    {
        osc_current_admin_theme_path($file);
    }
}
