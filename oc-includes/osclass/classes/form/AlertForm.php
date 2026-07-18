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
 * Class AlertForm
 */
class AlertForm extends Form
{

    /**
     * @return bool
     */
    public static function user_id_hidden()
    {
        parent::generic_input_hidden('alert_userId', osc_logged_user_id());

        return true;
    }

    /**
     * @return bool
     */
    public static function email_hidden()
    {
        parent::generic_input_hidden('alert_email', osc_logged_user_email());

        return true;
    }

    /**
     * @return bool
     */
    public static function email_text()
    {
        $value = '';
        if (osc_logged_user_email() == '') {
            $value = self::default_email_text();
        }
        parent::generic_input_text('alert_email', $value);

        return true;
    }

    /**
     * @return string
     */
    public static function default_email_text()
    {
        return __('Enter your e-mail');
    }

    /**
     * @return bool
     */
    public static function page_hidden()
    {
        parent::generic_input_hidden('page', 'search');

        return true;
    }

    /**
     * @return bool
     */
    public static function alert_hidden()
    {
        parent::generic_input_hidden('alert', osc_search_alert());

        return true;
    }
}
