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
 * Class WebSecBaseModel
 */
use mindstellar\utility\AjaxResponse;

class WebSecBaseModel extends SecBaseModel
{
    /**
     * Whether a front-end user is logged in.
     *
     * @return bool
     */
    public function isLogged()
    {
        return osc_is_web_user_logged_in();
    }

    //destroying current session
    /**
     * Clears the front-end user's session, ephemeral identity and remember-me cookies.
     *
     * @return void
     */
    public function logout()
    {
        // The chosen locale lives in its own cookie now, so it survives logout without
        // restarting a session — a logged-out visitor is left session-free (cacheable).
        Session::getInstance()->session_end();
        Session::getInstance()->_drop('userId');
        Session::getInstance()->_drop('userName');
        Session::getInstance()->_drop('userEmail');
        Session::getInstance()->_drop('userPhone');
        // Identity is now cookie-backed and mirrored into a request-scoped ephemeral store;
        // clear both so nothing this request still reads as logged in.
        Session::getInstance()->_dropEphemeral('userId');
        Session::getInstance()->_dropEphemeral('userName');
        Session::getInstance()->_dropEphemeral('userEmail');
        Session::getInstance()->_dropEphemeral('userPhone');
        View::getInstance()->_erase('_loggedUser');

        Cookie::getInstance()->pop('oc_userId');
        Cookie::getInstance()->pop('oc_userSecret');
        Cookie::getInstance()->set();
    }

    /**
     * Answers an ajax request with a session-timeout error, otherwise redirects to the user login.
     *
     * @return void
     */
    public function showAuthFailPage()
    {
        if (Params::getParam('page') === 'ajax') {
            AjaxResponse::json(array('error' => 1, 'msg' => __('Session timed out')));
            exit;
        }

        $this->redirectTo(osc_user_login_url());
        exit;
    }
}

/* file end: ./oc-includes/osclass/core/WebSecBaseModel.php */
