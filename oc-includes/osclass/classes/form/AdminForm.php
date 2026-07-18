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
 * Class AdminForm
 */
class AdminForm extends Form
{

    /**
     * @param $admin
     */
    public static function primary_input_hidden($admin)
    {
        parent::generic_input_hidden('id', (isset($admin['pk_i_id']) ? $admin['pk_i_id'] : ''));
    }

    /**
     * @param null $admin
     */
    public static function name_text($admin = null)
    {
        parent::generic_input_text('s_name', isset($admin['s_name']) ? $admin['s_name'] : '');
    }

    /**
     * @param null $admin
     */
    public static function username_text($admin = null)
    {
        parent::generic_input_text('s_username',
            isset($admin['s_username']) ? $admin['s_username'] : '');
    }

    /**
     * @param null $admin
     */
    public static function old_password_text($admin = null)
    {
        parent::generic_password('old_password', '');
    }

    /**
     * @param null $admin
     */
    public static function password_text($admin = null)
    {
        parent::generic_password('s_password', '');
    }

    /**
     * @param null $admin
     */
    public static function check_password_text($admin = null)
    {
        parent::generic_password('s_password2', '');
    }

    /**
     * @param null $admin
     */
    public static function email_text($admin = null)
    {
        parent::generic_input_text('s_email', isset($admin['s_email']) ? $admin['s_email'] : '');
    }

    /**
     * @param null $admin
     */
    public static function type_select($admin = null)
    {
        $options = array(
            array('i_value' => '0', 's_text' => __('Administrator')),
            array('i_value' => '1', 's_text' => __('Moderator'))
        );

        parent::generic_select('b_moderator', $options, 'i_value', 's_text', null,
            isset($admin['b_moderator']) ? $admin['b_moderator'] : null);
    }

    public static function js_validation()
    {
        // Admin-only form: uses the admin's native validator (ui-osc.js), not jQuery.
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                oscValidateForm(document.querySelector('form[name="admin_form"]'), {
                    rules: {
                        s_name: {required: true, minlength: 3, maxlength: 50},
                        s_username: {required: true, minlength: 3, maxlength: 50},
                        s_email: {required: true, email: true},
                        s_password: {minlength: 5},
                        s_password2: {
                            minlength: 5,
                            custom: function (v, form) {
                                return v === form.querySelector('[name="s_password"]').value;
                            }
                        }
                    },
                    messages: {
                        s_name: {
                            required: "<?php _e('Name: this field is required'); ?>.",
                            minlength: "<?php _e('Name: enter at least 3 characters'); ?>.",
                            maxlength: "<?php _e('Name: no more than 50 characters'); ?>."
                        },
                        s_username: {
                            required: "<?php _e('Username: this field is required'); ?>.",
                            minlength: "<?php _e('Username: enter at least 3 characters'); ?>.",
                            maxlength: "<?php _e('Username: no more than 50 characters'); ?>."
                        },
                        s_email: {
                            required: "<?php _e('Email: this field is required'); ?>.",
                            email: "<?php _e('Invalid email address'); ?>."
                        },
                        s_password: {minlength: "<?php _e('Password: enter at least 5 characters'); ?>."},
                        s_password2: {custom: "<?php echo osc_esc_js(__("Passwords don't match")); ?>."}
                    },
                    errorContainer: '#error_list',
                    onInvalid: function () {
                        window.scrollTo({top: 0, behavior: 'smooth'});
                    }
                });
            });
        </script>
        <?php
    }
}

/* file end: ./oc-includes/osclass/form/AdminForm.php */