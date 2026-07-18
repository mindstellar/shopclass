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

use mindstellar\utility\Validate;

/**
 * Class CWebLanguage
 */
class CWebLanguage extends BaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_language');
    }

    // business layer...
    public function doModel()
    {
        $locale = Params::getParam('locale');
        if ($locale && (new Validate())->localeCode($locale)) {
            Session::newInstance()->_set('userLocale', $locale);
        }

        $redirect_url = '';
        if (Params::getServerParam('HTTP_REFERER', false, false)) {
            $redirect_url = Params::getServerParam('HTTP_REFERER', false, false);
        } else {
            $redirect_url = osc_base_url(true);
        }

        $this->redirectTo($redirect_url);
    }

    // hopefully generic...

    /**
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
    }
}

/* file end: ./CWebLanguage.php */
