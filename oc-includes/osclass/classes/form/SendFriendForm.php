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
 * Class SendFriendForm
 */
class SendFriendForm extends Form
{

    /*static public function primary_input_hidden($page) {
        parent::generic_input_hidden("id", $page["pk_i_id"]);
    }*/

    /**
     * @return bool
     */
    public static function your_name()
    {

        if (Session::newInstance()->_getForm('yourName') != '') {
            $yourName = Session::newInstance()->_getForm('yourName');
            parent::generic_input_text('yourName', $yourName);
        } else {
            parent::generic_input_text('yourName', '');
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function your_email()
    {

        if (Session::newInstance()->_getForm('yourEmail') != '') {
            $yourEmail = Session::newInstance()->_getForm('yourEmail');
            parent::generic_input_text('yourEmail', $yourEmail);
        } else {
            parent::generic_input_text('yourEmail', '');
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function friend_name()
    {
        if (Session::newInstance()->_getForm('friendName') != '') {
            $friendName = Session::newInstance()->_getForm('friendName');
            parent::generic_input_text('friendName', $friendName);
        } else {
            parent::generic_input_text('friendName', '');
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function friend_email()
    {
        if (Session::newInstance()->_getForm('friendEmail') != '') {
            $friendEmail = Session::newInstance()->_getForm('friendEmail');
            parent::generic_input_text('friendEmail', $friendEmail);
        } else {
            parent::generic_input_text('friendEmail', '');
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function your_message()
    {
        if (Session::newInstance()->_getForm('message_body') != '') {
            $message_body = Session::newInstance()->_getForm('message_body');
            parent::generic_textarea('message', $message_body);
        } else {
            parent::generic_textarea('message', '');
        }

        return true;
    }

    public static function js_validation()
    {
        // Self-contained vanilla validation (no jQuery / jquery-validate); depends on no
        // external helper so it runs on any public theme.
        ?>
        <script type="text/javascript">
            (function () {
                var form = document.querySelector('form[name="sendfriend"]');
                if (!form) { return; }
                var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                var fields = [
                    {name: 'yourName', required: "<?php echo osc_esc_js(__('Your name: this field is required')); ?>."},
                    {
                        name: 'yourEmail',
                        required: "<?php echo osc_esc_js(__('Email: this field is required')); ?>.",
                        email: "<?php echo osc_esc_js(__('Invalid email address')); ?>."
                    },
                    {name: 'friendName', required: "<?php echo osc_esc_js(__("Friend's name: this field is required")); ?>."},
                    {
                        name: 'friendEmail',
                        required: "<?php echo osc_esc_js(__("Friend's email: this field is required")); ?>.",
                        email: "<?php echo osc_esc_js(__("Invalid friend's email address")); ?>."
                    },
                    {name: 'message', required: "<?php echo osc_esc_js(__('Message: this field is required')); ?>."}
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
