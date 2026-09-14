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
 * Class AlertForm
 */
class AlertForm extends Form
{
    /**
     * Echo the hidden input carrying the logged-in user id.
     *
     * @return bool always true
     */
    public static function user_id_hidden()
    {
        parent::generic_input_hidden('alert_userId', osc_logged_user_id());

        return true;
    }

    /**
     * Echo the hidden input carrying the logged-in user email.
     *
     * @return bool always true
     */
    public static function email_hidden()
    {
        parent::generic_input_hidden('alert_email', osc_logged_user_email());

        return true;
    }

    /**
     * Echo the alert email text input, pre-filled with a placeholder for guests.
     *
     * @return bool always true
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
     * The placeholder text shown in the alert email field.
     *
     * @return string
     */
    public static function default_email_text()
    {
        return __('Enter your e-mail');
    }

    /**
     * Echo the hidden input pinning the alert form to the search page.
     *
     * @return bool always true
     */
    public static function page_hidden()
    {
        parent::generic_input_hidden('page', 'search');

        return true;
    }

    /**
     * Echo the hidden input carrying the serialised search alert.
     *
     * @return bool always true
     */
    public static function alert_hidden()
    {
        parent::generic_input_hidden('alert', osc_search_alert());

        return true;
    }
}
