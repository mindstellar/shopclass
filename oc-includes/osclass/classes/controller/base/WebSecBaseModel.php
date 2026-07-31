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
 * Class WebSecBaseModel
 */
class WebSecBaseModel extends SecBaseModel
{
    /**
     * @return bool
     */
    public function isLogged()
    {
        return osc_is_web_user_logged_in();
    }

    //destroying current session
    public function logout()
    {
        //destroying session
        // The chosen locale lives in its own cookie now, so it survives logout without
        // restarting a session — a logged-out visitor is left session-free (cacheable).
        Session::newInstance()->session_destroy();
        Session::newInstance()->_drop('userId');
        Session::newInstance()->_drop('userName');
        Session::newInstance()->_drop('userEmail');
        Session::newInstance()->_drop('userPhone');
        // Identity is now cookie-backed and mirrored into a request-scoped ephemeral store;
        // clear both so nothing this request still reads as logged in.
        Session::newInstance()->_dropEphemeral('userId');
        Session::newInstance()->_dropEphemeral('userName');
        Session::newInstance()->_dropEphemeral('userEmail');
        Session::newInstance()->_dropEphemeral('userPhone');
        View::newInstance()->_erase('_loggedUser');

        Cookie::newInstance()->pop('oc_userId');
        Cookie::newInstance()->pop('oc_userSecret');
        Cookie::newInstance()->set();
    }

    public function showAuthFailPage()
    {
        if (Params::getParam('page') === 'ajax') {
            echo json_encode(array('error' => 1, 'msg' => __('Session timed out')));
            exit;
        }

        $this->redirectTo(osc_user_login_url());
        exit;
    }
}

/* file end: ./oc-includes/osclass/core/WebSecBaseModel.php */
