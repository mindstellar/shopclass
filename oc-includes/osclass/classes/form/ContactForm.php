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
 * Class ContactForm
 */
class ContactForm extends Form
{
    /**
     * Echo the hidden input carrying the item id being contacted about.
     *
     * @return bool always true
     */
    public static function primary_input_hidden()
    {
        parent::generic_input_hidden('id', osc_item_id());

        return true;
    }

    /**
     * Echo the hidden input pinning the contact form to the item page.
     *
     * @return bool always true
     */
    public static function page_hidden()
    {
        parent::generic_input_hidden('page', 'item');

        return true;
    }

    /**
     * Echo the hidden input carrying the contact_post action.
     *
     * @return bool always true
     */
    public static function action_hidden()
    {
        parent::generic_input_hidden('action', 'contact_post');

        return true;
    }

    /**
     * Echo the sender name input, preferring the failed-submit session value.
     *
     * @return bool always true
     */
    public static function your_name()
    {
        if (Session::getInstance()->_getForm('yourName') != '') {
            $name = Session::getInstance()->_getForm('yourName');
            parent::generic_input_text('yourName', $name);
        } else {
            parent::generic_input_text('yourName', osc_logged_user_name());
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
            $email = Session::getInstance()->_getForm('yourEmail');
            parent::generic_input_text('yourEmail', $email);
        } else {
            parent::generic_input_text('yourEmail', osc_logged_user_email());
        }

        return true;
    }

    /**
     * Echo the sender phone input, preferring the failed-submit session value.
     *
     * @return bool always true
     */
    public static function your_phone_number()
    {
        if (Session::getInstance()->_getForm('phoneNumber') != '') {
            $phoneNumber = Session::getInstance()->_getForm('phoneNumber');
            parent::generic_input_text('phoneNumber', $phoneNumber);
        } else {
            parent::generic_input_text('phoneNumber', osc_logged_user_phone());
        }

        return true;
    }

    /**
     * Echo the subject input, preferring the failed-submit session value.
     *
     * @return bool always true
     */
    public static function the_subject()
    {
        if (Session::getInstance()->_getForm('subject') != '') {
            $subject = Session::getInstance()->_getForm('subject');
            parent::generic_input_text('subject', $subject);
        } else {
            parent::generic_input_text('subject', '');
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
            $message = Session::getInstance()->_getForm('message_body');
            parent::generic_textarea('message', $message);
        } else {
            parent::generic_textarea('message', '');
        }

        return true;
    }

    /**
     * Echo the attachment file input.
     *
     * @return void
     */
    public static function your_attachment()
    {
        echo '<input type="file" name="attachment" />';
    }

    /**
     * Echo (or enqueue) the client-side validation script for the contact form.
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
        <script>
            <?php echo self::validationJs('contact_form', [
                'message'   => ['required' => true],
                'yourEmail' => ['required' => true, 'email' => true],
            ], [
                'message'   => __('Message: this field is required') . '.',
                'yourEmail' => [
                    'required' => __('Email: this field is required') . '.',
                    'email'    => __('Invalid email address') . '.',
                ],
            ]); ?>
        </script>
        <?php
        if ($enqueue) {
            Scripts::enqueueScriptCode((string) ob_get_clean(), null, false, 'contact_form_js');
        }
    }
}
