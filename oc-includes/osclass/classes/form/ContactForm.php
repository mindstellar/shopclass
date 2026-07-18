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
 * Class ContactForm
 */
class ContactForm extends Form
{

    /**
     * @return bool
     */
    public static function primary_input_hidden()
    {
        parent::generic_input_hidden('id', osc_item_id());

        return true;
    }

    /**
     * @return bool
     */
    public static function page_hidden()
    {
        parent::generic_input_hidden('page', 'item');

        return true;
    }

    /**
     * @return bool
     */
    public static function action_hidden()
    {
        parent::generic_input_hidden('action', 'contact_post');

        return true;
    }

    /**
     * @return bool
     */
    public static function your_name()
    {
        if (Session::newInstance()->_getForm('yourName') != '') {
            $name = Session::newInstance()->_getForm('yourName');
            parent::generic_input_text('yourName', $name);
        } else {
            parent::generic_input_text('yourName', osc_logged_user_name());
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function your_email()
    {
        if (Session::newInstance()->_getForm('yourEmail') != '') {
            $email = Session::newInstance()->_getForm('yourEmail');
            parent::generic_input_text('yourEmail', $email);
        } else {
            parent::generic_input_text('yourEmail', osc_logged_user_email());
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function your_phone_number()
    {
        if (Session::newInstance()->_getForm('phoneNumber') != '') {
            $phoneNumber = Session::newInstance()->_getForm('phoneNumber');
            parent::generic_input_text('phoneNumber', $phoneNumber);
        } else {
            parent::generic_input_text('phoneNumber', osc_logged_user_phone());
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function the_subject()
    {
        if (Session::newInstance()->_getForm('subject') != '') {
            $subject = Session::newInstance()->_getForm('subject');
            parent::generic_input_text('subject', $subject);
        } else {
            parent::generic_input_text('subject', '');
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function your_message()
    {
        if (Session::newInstance()->_getForm('message_body') != '') {
            $message = Session::newInstance()->_getForm('message_body');
            parent::generic_textarea('message', $message);
        } else {
            parent::generic_textarea('message', '');
        }

        return true;
    }

    public static function your_attachment()
    {
        echo '<input type="file" name="attachment" />';
    }

    public static function js_validation()
    {
        // Self-contained vanilla validation (no jQuery / jquery-validate); depends on no
        // external helper so it runs on any public theme.
        ?>
        <script>
            (function () {
                var form = document.querySelector('form[name="contact_form"]');
                if (!form) { return; }
                var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                var fields = [
                    {name: 'message', required: "<?php echo osc_esc_js(__('Message: this field is required')); ?>."},
                    {
                        name: 'yourEmail',
                        required: "<?php echo osc_esc_js(__('Email: this field is required')); ?>.",
                        email: "<?php echo osc_esc_js(__('Invalid email address')); ?>."
                    }
                ];
                form.addEventListener('submit', function (e) {
                    var errors = [];
                    form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
                    fields.forEach(function (f) {
                        var el = form.querySelector('[name="' + f.name + '"]');
                        if (!el) { return; }
                        var v = el.value.trim(), msg = null;
                        if (v === '') { msg = f.required; }
                        else if (f.email && !emailRe.test(v)) { msg = f.email; }
                        if (msg) { errors.push({el: el, msg: msg}); el.classList.add('is-invalid'); }
                    });
                    var container = document.querySelector('#error_list');
                    if (container) {
                        container.innerHTML = '';
                        errors.forEach(function (er) { var li = document.createElement('li'); li.textContent = er.msg; container.appendChild(li); });
                    }
                    if (errors.length) {
                        e.preventDefault();
                        window.scrollTo({top: 0, behavior: 'smooth'});
                        if (errors[0].el && errors[0].el.focus) { errors[0].el.focus(); }
                    } else {
                        form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (b) { b.disabled = true; });
                    }
                });
            })();
        </script>
        <?php
    }
}
