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
 * Description of BaseModel
 *
 * @author danielo
 */
class SecBaseModel extends BaseModel
{
    private $grant;

    /**
     * Boots the base controller and refuses the request when the visitor is not logged in.
     */
    public function __construct()
    {
        parent::__construct();

        //Checking granting...
        $this->init();
    }

    /**
     * Logs out and shows the auth-failure page unless the visitor is logged in.
     *
     * @return void
     */
    protected function init()
    {
        if (!$this->isLogged()) {
            //If we are not logged or we do not have permissions -> go to the login page
            $this->logout();
            $this->showAuthFailPage();
        }
    }

    /**
     * Destroys the session.
     *
     * @return void
     */
    public function logout()
    {
        //destroying session
        Session::newInstance()->session_destroy();
    }

    //destroying current session

    /**
     * Stores the granting object used to authorise the request.
     *
     * @param mixed $grant
     *
     * @return void
     */
    public function setGranting($grant)
    {
        $this->grant = $grant;
    }

    /**
     * No-op: secured pages that need model work override this.
     *
     * @return void
     */
    public function doModel()
    {
    }

    /**
     * No-op: secured pages that render a template override this.
     *
     * @param string $file
     *
     * @return void
     */
    public function doView($file)
    {
    }
}

/* file end: ./oc-includes/osclass/core/SecBaseModel.php */
