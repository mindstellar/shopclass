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
 * Class SendFriendForm
 */
class SendFriendForm extends Form
{
    /*static public function primary_input_hidden($page) {
        parent::generic_input_hidden("id", $page["pk_i_id"]);
    }*/

    /**
     * Echo the sender name input, preferring the failed-submit session value.
     *
     * @return bool always true
     */
    public static function your_name()
    {

        if (Session::getInstance()->_getForm('yourName') != '') {
            $yourName = Session::getInstance()->_getForm('yourName');
            parent::generic_input_text('yourName', $yourName);
        } else {
            parent::generic_input_text('yourName', '');
        }

        return true;
    }

    /**
     * Echo the sender email input, preferring the failed-submit session value.
     *
     * @return bool always true
     */
    public static function your_email()
    {

        if (Session::getInstance()->_getForm('yourEmail') != '') {
            $yourEmail = Session::getInstance()->_getForm('yourEmail');
            parent::generic_input_text('yourEmail', $yourEmail);
        } else {
            parent::generic_input_text('yourEmail', '');
        }

        return true;
    }

    /**
     * Echo the recipient name input, preferring the failed-submit session value.
     *
     * @return bool always true
     */
    public static function friend_name()
    {
        if (Session::getInstance()->_getForm('friendName') != '') {
            $friendName = Session::getInstance()->_getForm('friendName');
            parent::generic_input_text('friendName', $friendName);
        } else {
            parent::generic_input_text('friendName', '');
        }

        return true;
    }

    /**
     * Echo the recipient email input, preferring the failed-submit session value.
     *
     * @return bool always true
     */
    public static function friend_email()
    {
        if (Session::getInstance()->_getForm('friendEmail') != '') {
            $friendEmail = Session::getInstance()->_getForm('friendEmail');
            parent::generic_input_text('friendEmail', $friendEmail);
        } else {
            parent::generic_input_text('friendEmail', '');
        }

        return true;
    }

    /**
     * Echo the message textarea, preferring the failed-submit session value.
     *
     * @return bool always true
     */
    public static function your_message()
    {
        if (Session::getInstance()->_getForm('message_body') != '') {
            $message_body = Session::getInstance()->_getForm('message_body');
            parent::generic_textarea('message', $message_body);
        } else {
            parent::generic_textarea('message', '');
        }

        return true;
    }

    /**
     * Echo (or enqueue) the client-side validation script for the send-to-friend form.
     *
     * @param bool $enqueue Buffer the script and hand it to Scripts::enqueueScriptCode
     *
     * @return void
     */
    public static function js_validation($enqueue = false)
    {
        if ($enqueue) {
            ob_start();
        }
        ?>
        <script type="text/javascript">
            <?php echo self::validationJs('sendfriend', [
                'yourName'    => ['required' => true],
                'yourEmail'   => ['required' => true, 'email' => true],
                'friendName'  => ['required' => true],
                'friendEmail' => ['required' => true, 'email' => true],
                'message'     => ['required' => true],
            ], [
                'yourName'    => __('Your name: this field is required') . '.',
                'yourEmail'   => [
                    'required' => __('Email: this field is required') . '.',
                    'email'    => __('Invalid email address') . '.',
                ],
                'friendName'  => __("Friend's name: this field is required") . '.',
                'friendEmail' => [
                    'required' => __("Friend's email: this field is required") . '.',
                    'email'    => __("Invalid friend's email address") . '.',
                ],
                'message'     => __('Message: this field is required') . '.',
            ]); ?>
        </script>
        <?php
        if ($enqueue) {
            Scripts::enqueueScriptCode((string) ob_get_clean(), null, false, 'send_friend_form_js');
        }
    }
}
